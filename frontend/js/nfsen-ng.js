var config;
var dygraph;
var dygraph_config;
var dygraph_data;
var dygraph_rangeselector_active;
var dygraph_daterange;
var dygraph_did_zoom;
var date_range;
var api_graph_options;

$(document).ready(function() {

    /**
     * get config from backend
     * example data:
     *
     *  config object {
     *    "sources": {
     *      "gate": [false,false], // timestamp start, timestamp end
     *      "swi6": [false,false]
     *    },
     *    "ports": [ 80, 23, 22 ],
     *    "stored_output_formats":[],
     *    "stored_filters":[]
     *  }
     */
    $.get('../api/config', function(data, status) {
        if (status === 'success') {
            config = data;
            init();
        } else {
            // todo probably a red half-transparent overlay over the whole page?
            console.log('There was a problem with getting the config.');
        }
    });

    /**
     * navigation functionality
     * show/hides the correct containers, which are identified by the data-view attribute
     */
    $(document).on('click', 'header a', function(e) {
        e.preventDefault();
        var view = $(this).attr('data-view');
        var $filter = $('#filter').find('[data-view]');
        var $content = $('#contentDiv').find('div.content');

        $('header li').removeClass('active');
        $(this).parent().addClass('active');

        var showDivs = function(id, el) {
            if ($(el).attr('data-view').indexOf(view) !== -1) $(el).removeClass('hidden');
            else $(el).addClass('hidden');
        };

        $filter.each(showDivs);
        $content.each(showDivs);
    });

    /**
     * date range slider
     * set next/previous time slot
     */
    $(document).on('click', '#date_slot_nav button', function() {
        var slot = parseInt($('#date_slot').find('input[name=range]:checked').val()),
            prev = $(this).hasClass('prev');
        if (isNaN(slot)) slot = date_range.options.to-date_range.options.from;
        if (slot > (date_range.options.max-date_range.options.min)/2) return;

        date_range.update({
            from: prev === true ? date_range.options.from-slot : date_range.options.from+slot,
            to: prev === true ? date_range.options.to-slot : date_range.options.to+slot
        });
    });

    /**
     * date range slider
     * set predefined time range like day/week/month/year
     */
    $(document).on('change', 'input[name=range]', function() {
        date_range.update({
            from: date_range.options.to-$(this).val(),
            to: date_range.options.to
        });
    });

    /**
     * source filter
     * reload the graph when the source selection changes
     */
    $(document).on('change', '#filterSourcesSelect', updateGraph);

    /**
     * displays the right filter
     */
    $(document).on('change', '#filterDisplaySelect', function() {
        var display = $(this).val(), displayId;
        var $filters = $('#filter').find('[data-display]').addClass('hidden');
        var $filterElements = $filters.filter('[data-display*=' + display + ']').removeClass('hidden');

        switch (display) {
            case 'sources':
                displayId = '#filterSources';
                $('#filterProtocols').find('input').attr('checked', false); // todo generalize...
                displaySourcesHelper();
                break;
            case 'protocols':
                displayId = '#filterProtocols';
                displayProtocolsHelper();
                break;
            case 'ports':
                displayId = '#filterPorts';
                displayPortsHelper();
                break;
        }

        // move wanted filter to first position
        $(displayId).detach().insertBefore($filters.eq(0));

        // try to update graph
        updateGraph();
    });
    /**
     * protocols filter
     * reload the graph when the protocol selection changes
     */
    $(document).on('change', '#filterProtocols input', updateGraph);

    /**
     * datatype filter (flows/packets/bytes)
     * reload the graph... you get it by now
     */
    $(document).on('change', '#filterTypes input', updateGraph);

    $(document).on('change', '#filterPortsSelect', updateGraph);

    /**
     * show/hide series in the dygraph
     * todo: check if this is needed at all, as it's the same like in the filter
     */
    $(document).on('change', '#series input', function(e) {
        var $checkbox = $(e.target);
        dygraph.setVisibility($checkbox.parent().index(), $($checkbox).is(':checked'));
    });

    /**
     * set graph display to lines or stacked
     */
    $(document).on('change', '#graph_linestacked input', function() {
        var stacked = ($(this).val() === 'stacked');

        dygraph.updateOptions({
            stackedGraph : stacked,
            fillGraph: stacked
        });
    });

    /**
     * scale graph display linear or logarithmic
     */
    $(document).on('change', '#graph_linlog input', function() {
        var linear = ($(this).val() === 'linear');

        dygraph.updateOptions({
            logscale : !linear
        });
    });

    /**
     * initialize the frontend
     * - set the select-list of sources
     * - initialize the range slider
     * - load the graph
     */
    function init() {
        // load default filter
        $('header li a').eq(0).trigger('click');

        // show correct form elements
        $('#filterDisplaySelect').trigger('change');

        // load values for form
        updateDropdown('sources', Object.keys(config['sources']));
        updateDropdown('ports', config['ports']);

        var now = new Date();
        dygraph_daterange = [new Date().setFullYear(now.getFullYear()-3), now];

        init_rangeslider();

        updateGraph();
    }

    /**
     * initialize the range slider
     */
    function init_rangeslider() {
        // initialize date range slider
        $('#date_range').ionRangeSlider({
            type: 'double',
            grid: true,
            min: dygraph_daterange[0],
            max: dygraph_daterange[1],
            force_edges: true,
            drag_interval: true,
            prettify: function(ut) {
                var date = new Date(ut);
                return date.toDateString();
            },
            onChange: function(data) {
                $('#date_slot').find('label.active').removeClass('active').find('input').attr('checked', false);
                // somehow still has old from/to
            },
            onFinish: function(data) {
                dygraph_daterange = [new Date(data.from), new Date(data.to)];
                updateGraph();
            },
            onUpdate: function(data) {
                dygraph_daterange = [new Date(data.from), new Date(data.to)];
                updateGraph();
            }
        });
        date_range = $('#date_range').data('ionRangeSlider');
    }

    /**
     * initialize two dygraph mods
     * they are needed to dynamically load more detailed data as the user zooms in or pans around
     * heavily influenced by https://github.com/kaliatech/dygraphs-dynamiczooming-example
     */
    function init_dygraph_mods() {
        dygraph_rangeselector_active = false;
        var $rangeEl = $('#flowDiv').find('.dygraph-rangesel-fgcanvas, .dygraph-rangesel-zoomhandle');

        // uninstall existing handler if already installed
        $rangeEl.off('mousedown.dygraph touchstart.dygraph');

        // install new mouse down handler
        $rangeEl.on('mousedown.dygraph touchstart.dygraph', function () {

            // track that mouse is down on range selector
            dygraph_rangeselector_active = true;

            // setup mouse up handler to initiate new data load
            $(window).off('mouseup.dygraph touchend.dygraph'); //cancel any existing
            $(window).on('mouseup.dygraph touchend.dygraph', function () {
                $(window).off('mouseup.dygraph touchend.dygraph');

                // mouse no longer down on range selector
                dygraph_rangeselector_active = false;

                // get the new detail window extents
                dygraph_daterange = dygraph.xAxisRange();
                dygraph_did_zoom = true;
                updateGraph();
            });
        });

        // save original endPan function
        var origEndPan = Dygraph.defaultInteractionModel.endPan;

        // replace built-in handling with our own function
        Dygraph.defaultInteractionModel.endPan = function (event, g, context) {

            // call the original to let it do it's magic
            origEndPan(event, g, context);

            // extract new start/end from the x-axis
            dygraph_daterange = g.xAxisRange();
            dygraph_did_zoom = true;
            updateGraph();
        };
        Dygraph.endPan = Dygraph.defaultInteractionModel.endPan; // see dygraph-interaction-model.js
    }

    /**
     * zoom callback for dygraph
     * updates the graph when the rangeselector is not active
     * @param minDate
     * @param maxDate
     */
    function dygraph_zoom(minDate, maxDate) {
        dygraph_daterange = [new Date(minDate), new Date(maxDate)];

        //When zoom reset via double-click, there is no mouse-up event in chrome (maybe a bug?),
        //so we initiate data load directly
        if (dygraph.isZoomed('x') === false) {
            dygraph_did_zoom = true;
            $(window).off('mouseup touchend'); //Cancel current event handler if any
            updateGraph();
            return;
        }

        //The zoom callback is called when zooming via mouse drag on graph area, as well as when
        //dragging the range selector bars. We only want to initiate dataload when mouse-drag zooming. The mouse
        //up handler takes care of loading data when dragging range selector bars.
        if (!dygraph_rangeselector_active) {
            dygraph_did_zoom = true;
            updateGraph();
        }

    }

    /**
     * reads options from api_graph_options, performs a request on the API
     * and tries to display the received data in the dygraph.
     */
    function updateGraph() {
        var sources = $('#filterSourcesSelect').val(),
            type = $('#filterTypes input:checked').val(),
            ports = $('#filterPortsSelect').val(),
            protos = $('#filterProtocols').find('input:checked').map(function() { return $(this).val(); }).get(),
            display = $('#filterDisplaySelect').val(),
            title = type + ' for ';

        // check if options valid to request new dygraph
        if (typeof sources === 'string') sources = [sources];
        if (sources.length === 0) {
            if (display === 'ports')
                sources = ['any'];
            else return;
        }
        if (protos.length > 1 && sources.length > 1) return; // todo annotate wrong input?
        if (ports.length === 0) ports = [0];

        // set options
        api_graph_options = {
            datestart: parseInt(dygraph_daterange[0]/1000),
            dateend: parseInt(dygraph_daterange[1]/1000),
            type: type,
            protocols: protos.length > 0 ? protos : ['any'],
            sources: sources,
            ports: ports,
            display: display
        };

        // set title todo: make it depend on what is displayed (protocols/sources/ports)
        if (protos.length > sources.length) {
            title += 'protocols ' + protos.join(', ') + ' (' + sources[0] + ')';
        } else {
            var s = sources.length > 1 ? 's' : ''; // plural

            // if more than 3, only show number of sources instead of names
            if (sources.length > 3) title += sources.length + ' sources';
            else title += 'source' + s + ' ' + sources.join(', ');

            title += ' (' + (protos.length === 0 ? 'any' : protos[0]) + ')';
        }

        // make actual request
        $.get('../api/graph', api_graph_options, function (data, status) {
            if (status === 'success') {

                var labels = ['Date'], index_to_insert = false;

                // iterate over labels
                $('#series').empty();
                $.each(data.legend, function (id, legend) {
                    labels.push(legend);

                    $('#series').append('<label><input type="checkbox" checked> ' + legend + '</label>');
                });

                // transform data to something Dygraph understands
                if (dygraph_did_zoom !== true) {
                    // reset dygraph data to get a fresh load
                    dygraph_data = [];
                } else {
                    // delete values to replace
                    for (var i = 0; i < dygraph_data.length; i++) {
                        if (dygraph_data[i][0].getTime() >= dygraph_daterange[0] && dygraph_data[i][0].getTime() <= dygraph_daterange[1]) {
                            // set start index for the new values
                            if (index_to_insert === false) index_to_insert = i;

                            // delete current element from array
                            dygraph_data.splice(i, 1);

                            // decrease current index, as all array elements moved left on deletion
                            i--;
                        }
                    }
                }

                // iterate over API result
                $.each(data.data, function (datetime, series) {
                    var position = [new Date(datetime * 1000)];

                    // add all serie values to position array
                    $.each(series, function (y, val) {
                        position.push(val);
                    });

                    // push position array to dygraph data
                    if (dygraph_did_zoom !== true) {
                        dygraph_data.push(position);
                    } else {
                        // when zoomed in, insert position array at the start index of replacement data
                        dygraph_data.splice(index_to_insert, 0, position);
                        index_to_insert++; // increase index, or data will get inserted backwards
                    }
                });

                if (typeof dygraph === 'undefined') {
                    // initial dygraph config:
                    dygraph_config = {
                        title: title,
                        labels: labels,
                        ylabel: type.toUpperCase() + '/s',
                        xlabel: 'TIME',
                        labelsKMB: true,
                        labelsDiv: $('#legend')[0],
                        labelsSeparateLines: true,
                        legend: 'always',
                        showRangeSelector: true,
                        dateWindow: [dygraph_data[0][0], dygraph_data[dygraph_data.length - 1][0]],
                        zoomCallback: dygraph_zoom,
                        highlightSeriesOpts: {
                            strokeWidth: 2,
                            strokeBorderWidth: 1,
                            highlightCircleSize: 5
                        },
                        rangeSelectorPlotStrokeColor: '#888888',
                        rangeSelectorPlotFillColor: '#cccccc',
                        stackedGraph: true,
                        fillGraph: true,
                    };
                    dygraph = new Dygraph($('#flowDiv')[0], dygraph_data, dygraph_config);
                    init_dygraph_mods();

                } else {
                    // update dygraph config
                    dygraph_config = {
                        // series: series,
                        // axes: axes,
                        ylabel: type.toUpperCase() + '/s',
                        title: title,
                        labels: labels,
                        file: dygraph_data,
                    };

                    if (dygraph_did_zoom === true) {
                        dygraph_config.dateWindow = dygraph_daterange;
                    } else {
                        // reset date window if we want to show entirely new data
                        dygraph_config.dateWindow = null;
                    }

                    dygraph.updateOptions(dygraph_config);
                }
                dygraph_did_zoom = false;

            } else {
                console.log('There was probably a problem with getting dygraph data.');
                // todo consequences?
            }
        });
    }

    /**
     * modify some GUI elements if the user selected "sources" to display
     */
    function displaySourcesHelper() {
        // add "multiple" to source selection and select all sources
        var $sourceSelect = $('#filterSourcesSelect'),
            $protocolButtons = $('#filterProtocolButtons');
        $sourceSelect.attr('multiple', true);

        // select all sources
        $sourceSelect.find('option').attr('selected', true);

        // uncheck protocol buttons and transform to radio buttons
        $protocolButtons.find('label').removeClass('active');
        $protocolButtons.find('label input').attr('checked', false).attr('type', 'radio');

        // select TCP proto as default
        $protocolButtons.find('label:first').addClass('active').find('input').attr('checked',true);
    }

    /**
     * modify some GUI elements if the user selected "protocols" to display
     */
    function displayProtocolsHelper() {
        // remove "multiple" from source select and select first source
        var $sourceSelect = $('#filterSourcesSelect'),
            $protocolButtons = $('#filterProtocolButtons');
        $sourceSelect.attr('multiple', false);
        $sourceSelect.find('option:first').attr('selected', true); // needed for the graph to be diplayed correctly

        // protocol buttons become checkboxes and get checked by default
        $protocolButtons.find('label input').attr('type', 'checkbox').attr('checked', true);
        $protocolButtons.find('label').addClass('active');
    }

    /**
     * modify some GUI elements if the user selected "ports" to display
     */
    function displayPortsHelper() {
        // remove "multiple" from source select
        var $sourceSelect = $('#filterSourcesSelect'),
            $portsSelect = $('#filterPortsSelect'),
            $protocolButtons = $('#filterProtocolButtons');
        $sourceSelect.attr('multiple', false);

        // uncheck protocol buttons and transform to radio buttons
        $protocolButtons.find('label').removeClass('active');
        $protocolButtons.find('label input').attr('checked', false).attr('type','radio');

        // select TCP proto as default
        $protocolButtons.find('label:first').addClass('active').find('input').attr('checked', true);

        // select all ports
        $portsSelect.find('option').attr('selected', true);
    }




    /**
     * gets the latest last date of all sources from the config
     * @param sources
     * @returns {number}
     */
    function getLastDate(sources) {
        var max = 0;
        $.each(sources, function(id, source) {
            var currentEnd = config['sources'][source][1]*1000;
            if (max === 0 || max < currentEnd) max = currentEnd;
        });
        return max;
    }

    /**
     * gets the firstmost first date of all sources from the config
     * @param sources
     * @returns {number}
     */
    function getFirstDate(sources) {
        var min = 0;
        $.each(sources, function(id, source) {
            var currentStart = config['sources'][source][0]*1000;
            if (min === 0 || min > currentStart) min = currentStart;
        });
        return min;
    }

    /**
     * updates the filter dropdowns with data
     * @param displaytype string: sources/ports/protocols
     * @param array array: the values to add
     */
    function updateDropdown(displaytype, array) {
        var id = '#filter' + displaytype.charAt(0).toUpperCase() + displaytype.slice(1);
        var $select = $(id).find('select');

        $.each(array, function(key, value) {
            $select
                .append($('<option></option>')
                .attr('value',value).text(value));
        });
    }
});