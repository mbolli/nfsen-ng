var config;
var dygraph;
var dygraph_config;
var dygraph_data;
var dygraph_rangeselector_active;
var dygraph_daterange;
var dygraph_did_zoom;
var date_range;
var api_graph_options;
var api_flows_options;

$(document).ready(function() {

    /**
     * get config from backend
     * example data:
     *
     *  config object {
     *    "sources": ["gate", "swi6"],
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
            display_error('danger', 'Error getting the config!')
        }
    });

    /**
     * general ajax error handler
     */
    $(document).on('ajaxError', function(e, jqXHR) {
        console.log(jqXHR);
        if (typeof jqXHR === 'undefined') {
            display_error('danger', 'General error, please file a ticket on github!');
        } else if (typeof jqXHR.responseJSON === 'undefined') {
            display_error('danger', 'General error: ' + jqXHR.responseText);
        } else {
            display_error('danger', 'Got ' + jqXHR.responseJSON.error);
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

    function check_daterange_boundaries(range) {
        var $buttons = $('#date_slot_nav').find('button');

        // reset next/prev buttons (depending on selected range)
        $buttons.filter('.next').prop('disabled', (date_range.options.to + range > date_range.options.max));
        $buttons.filter('.prev').prop('disabled', (date_range.options.from - range < date_range.options.min));
    }

    /**
     * date range slider
     * set next/previous time slot
     */
    $(document).on('click', '#date_slot_nav button', function() {
        var slot = parseInt($('#date_slot').find('input[name=range]:checked').val()),
            prev = $(this).hasClass('prev');

        // if the date_range was modified manually, get the difference
        if (isNaN(slot)) slot = date_range.options.to-date_range.options.from;

        date_range.update({
            from: prev === true ? date_range.options.from-slot : date_range.options.from+slot,
            to: prev === true ? date_range.options.to-slot : date_range.options.to+slot
        });

        // disable buttons if slot is too big or end is near
        check_daterange_boundaries(slot);
    });

    /**
     * date range slider
     * set predefined time range like day/week/month/year
     */
    $(document).on('change', 'input[name=range]', function() {
        var range = parseInt($(this).val());

        date_range.update({
            from: date_range.options.to - range,
            to: date_range.options.to // the current "to" value should stay
        });

        check_daterange_boundaries(range);
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

        // show only wanted filters
        $filters.filter('[data-display*=' + display + ']').removeClass('hidden');

        switch (display) {
            case 'sources':
                displayId = '#filterSources';
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
    $(document).on('change', '#filterProtocols input', function() {
        if ($(this).val() === 'any') {
            // uncheck all other input elements
            $(this).parent().addClass('active');
            $('#filterProtocols').find('input[value!="any"]').each(function () {
                $(this).prop('checked', false).parent().removeClass('active');
            });
        } else {
            // uncheck 'any' input element
            $('#filterProtocols').find('input[value="any"]').prop('checked', false).parent().removeClass('active');
        }
        updateGraph();
    });

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
        // load default view
        $('header li a').eq(0).trigger('click');

        // load values for form
        updateDropdown('sources', config['sources']);
        updateDropdown('ports', config['ports']);

        init_rangeslider();

        // show graph for one year by default
        $('#date_slot').find('[data-unit="y"]').trigger('change').parent().addClass('active');

        // show correct form elements
        $('#filterDisplaySelect').trigger('change');
    }

    /**
     * initialize the range slider
     */
    function init_rangeslider() {
        // set default date range
        var to = new Date();
        var from = new Date();
        from.setFullYear(to.getFullYear()-3);
        dygraph_daterange = [from, to];

        // initialize date range slider
        $('#date_range').ionRangeSlider({
            type: 'double',
            grid: true,
            min: dygraph_daterange[0].getTime(),
            max: dygraph_daterange[1].getTime(),
            force_edges: true,
            drag_interval: true,
            prettify: function(ut) {
                var date = new Date(ut);
                return date.toDateString();
            },
            onChange: function(data) {
                // remove active state of date slot button
                $('#date_slot').find('label.active').removeClass('active').find('input').prop('checked', false);
            },
            onFinish: function(data) {
                dygraph_daterange = [new Date(data.from), new Date(data.to)];
                date_range.update({ from: data.from, to: data.to });
                check_daterange_boundaries(data.to-data.from);
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
                var range = dygraph.xAxisRange();
                dygraph_daterange = [new Date(range[0]), new Date(range[1])];
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
            var range = g.xAxisRange();
            dygraph_daterange = [new Date(range[0]), new Date(range[1])];
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
            protocols = $('#filterProtocols').find('input:checked').map(function() { return $(this).val(); }).get(),
            display = $('#filterDisplaySelect').val(),
            title = type + ' for ';

        // check if options valid to request new dygraph
        if (typeof sources === 'string') sources = [sources];
        if (sources.length === 0) {
            if (display === 'ports')
                sources = ['any'];
            else return;
        }
        if (protocols.length > 1 && sources.length > 1) return; // todo annotate wrong input?
        if (ports.length === 0) ports = [0];

        // set options
        api_graph_options = {
            datestart: parseInt(dygraph_daterange[0].getTime()/1000),
            dateend: parseInt(dygraph_daterange[1].getTime()/1000),
            type: type,
            protocols: protocols.length > 0 ? protocols : ['any'],
            sources: sources,
            ports: ports,
            display: display
        };

        // set title
        var elements = eval(display);
        var cat = elements.length > 1 ? display : display.substr(0, display.length-1); // plural
        // if more than 4, only show number of sources instead of names
        if (elements.length > 4) title += cat + ' ' + elements.length;
        else title += cat + ' ' + elements.join(', ');


        // make actual request
        $.get('../api/graph', api_graph_options, function (data, status) {
            if (status === 'success') {
                if (data.data.length === 0) return false;

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
                        if (dygraph_data[i][0].getTime() >= dygraph_daterange[0].getTime() && dygraph_data[i][0].getTime() <= dygraph_daterange[1].getTime()) {
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
                display_error('warning', 'There somehow was a problem getting data, please check your form values.');
            }
        });
    }

    /**
     * Display an error message in the frontend
     * @param severity (success, info, warning, danger)
     * @param message
     */
    function display_error(severity, message) {
        var $error = $('#error'),
            $buttons = $('button[data-loading-text]'),
            icon;

        switch (severity) {
            case 'success': icon = 'ok'; break;
            case 'info': icon = 'certificate'; break;
            case 'warning': icon = 'warning-sign'; break;
            case 'danger': icon = 'alert'; break;
        }

        // create new error element
        $error.append('<div class="alert alert-dismissible" role="alert"><button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');

        // fill
        $error.find('div.alert').last().addClass('alert-' + severity).append('<span class="glyphicon glyphicon-' + icon + '" aria-hidden="true"></span> ' + message);

        // set default text to buttons, if needed
        $buttons.each(function() {
           $(this).button('reset');
        });
    }

    /**
     * Process flow listing
     *
     */
    $(document).on('click', '#getFlowDataBtn', function() {
        var sources = $('#filterSourcesSelect').val(),
            datestart = parseInt(dygraph_daterange[0].getTime()/1000),
            dateend = parseInt(dygraph_daterange[1].getTime()/1000),
            filter = ''+$('#flowsFilterTextarea').val(),
            limit = $('#flowsFilterLimitSelection').val(),
            aggregate = '',
            sort = '', // todo probably needs a new dropdown
            output = {
                format: $('#flowsFilterOutputSelection').val(),
            };

        $('#getFlowDataBtn').button('loading');

        // parse form values to generate a proper API request
        if ($('#biDirectionalFlowBtn').find(':checked').length === 0) {
            // todo check other parameters
            aggregate = 'srcip4/24';
        } else aggregate = 'bidirectional';

        api_flows_options = {
            datestart: datestart,
            dateend: dateend,
            sources: sources,
            filter: filter,
            limit: limit,
            aggregate: aggregate,
            sort: sort,
            output: output
        };

        var req = $.get('../api/flows', api_flows_options, function (data, status) {
            if (status === 'success') { // todo error handling

                // generate table header
                var translation = {
                        ff: 'flow record flags in hex', ts: 'Start Time - first seen', te: 'End Time - last seen', tr: 'Time the flow was received by the collector', td: 'Duration', pr: 'Protocol', exp: 'Exporter ID', eng: 'Engine Type/ID', sa: 'Source Address', da: 'Destination Address', sap: 'Source Address:Port', dap: 'Destination Address:Port', sp: 'Source Port', dp: 'Destination Port', sn: 'Source Network (mask applied)', dn: 'Destination Network (mask applied)', nh: 'Next-hop IP Address', nhb: 'BGP Next-hop IP Address', ra: 'Router IP Address', sas: 'Source AS', das: 'Destination AS', nas: 'Next AS', pas: 'Previous AS', in: 'Input Interface num', out: 'Output Interface num', pkt: 'Packets - default input', ipkt: 'Input Packets', opkt: 'Output Packets', byt: 'Bytes - default input', ibyt: 'Input Bytes', obyt: 'Output Bytes', fl: 'Flows', flg: 'TCP Flags', tos: 'Tos - default src', stos: 'Src Tos', dtos: 'Dst Tos', dir: 'Direction: ingress, egress', smk: 'Src mask', dmk: 'Dst mask', fwd: 'Forwarding Status', svln: 'Src vlan label', dvln: 'Dst vlan label', ismc: 'Input Src Mac Addr', odmc: 'Output Dst Mac Addr', idmc: 'Input Dst Mac Addr', osmc: 'Output Src Mac Addr'
                    },
                    tempcolumns = data[0],
                    columns = [];

                $.each(tempcolumns, function(i, val) {
                    // todo optimize breakpoints
                    var column = { name: val, title: translation[val], type: 'number', breakpoints: 'xs sm' };
                    switch (val) {
                        case 'ts':
                            column['type'] = 'text'; // 'date' needs moment.js library...
                            column['breakpoints'] = '';
                            break;
                        case 'sa':
                        case 'da':
                        case 'pr':
                            column['breakpoints'] = '';
                            column['type'] = 'text';
                            break;
                    }
                    columns.push(column);
                });

                // generate table data
                var temprows = data.slice(1, data.length-4),
                    rows = [];

                $.each(temprows, function(i, val) {
                    var row = { id: i };

                    $.each(val, function (j, col) {
                        row[tempcolumns[j]] = col;
                    });

                    rows.push(row);
                });

                // init footable
                $('#flowsContentDiv').find('table.table').footable({
                    columns: columns,
                    rows: rows
                });

                // remove errors
                $('#error').find('div.alert').fadeOut(1500, function() {$(this).remove(); });
            }

            // reset button label
            $('#getFlowDataBtn').button('reset');
        });
    });

    /**
     * Reset flow filter div parameters and "delete" flows from screen
     */
    $(document).on('click', '#resetFlowDataAndFilterBtn', function(){
        //todo implement function
    });


    /**
     * hide or show the custom output filter
     */
    $(document).on('change', '#flowsFilterOutputSelection', function() {

        // if "custom" is selected, show "customFlowListOutputFormat" otherwise hide it
        if ($(this).val() === 'custom') $('#customFlowListOutputFormat').removeClass('hidden');
        else $('#customFlowListOutputFormat').addClass('hidden');
    });

    /**
     * block not available options on "bi-direction" checked
     */

    $(document).on('change', '#biDirectionalFlowBtn', function() {
        var $filterFlowsAggregation = $('#filterFlowsAggregation');

        // if "bi-directional" is checked, block (disable) all other aggregation options
        if ($(this).hasClass('active')) {

            $filterFlowsAggregation.find('[data-disable-on="bi-directional"]').each(function() {
                $(this).parent().removeClass('active').addClass('disabled');
                $(this).prop('disabled', true);
                if ($(this).prop('tagName') === 'SELECT') $(this).prop('selectedIndex', 0);
                else $(this).val('');
            });

        } else {

            $filterFlowsAggregation.find('[data-disable-on="bi-directional"]').each(function() {
                $(this).parent().removeClass('disabled');
                $(this).prop('disabled', false);
            });

        }
    });


    /**
     * handle "onchange" for source/destination address(es) in aggregation filter
     */

    $(document).on('change', '#filterFlowAggregationSourceAddressSelect, #filterFlowAggregationDestinationAddressSelect', function() {
        var kind = $(this).attr('data-kind'),
            $prefixDiv = $('#' + kind + 'CIDRPrefixDiv'); console.log($prefixDiv);

        switch ($(this).val()) {
            case 'none':
            case 'srcip':
            case 'dstip':
                $prefixDiv.addClass('hidden');
                break;
            case 'srcip4sub':
            case 'dstip4sub':
                $prefixDiv.removeClass('hidden');
                $prefixDiv.find('input').attr('maxlength', 2).val('');
                break;
            case 'srcip6sub':
            case 'dstip6sub':
                $prefixDiv.removeClass('hidden');
                $prefixDiv.find('input').attr('maxlength', 3).val('');
                break;
        }
    });


    /**
     * modify some GUI elements if the user selected "sources" to display
     */
    function displaySourcesHelper() {
        // add "multiple" to source selection
        var $sourceSelect = $('#filterSourcesSelect'),
            $protocolButtons = $('#filterProtocolButtons');
        $sourceSelect.prop('multiple', true);

        // disable 'any' in sources
        $sourceSelect.find('option[value="any"]').prop('disabled', true);

        // select all sources
        $sourceSelect.find('option:not([disabled])').prop('selected', true);

        // uncheck protocol buttons and transform to radio buttons
        $protocolButtons.find('label').removeClass('active');
        $protocolButtons.find('label input').prop('checked', false).attr('type', 'radio');

        // select TCP proto as default
        $protocolButtons.find('label:first').addClass('active').find('input').prop('checked',true);
    }

    /**
     * modify some GUI elements if the user selected "protocols" to display
     */
    function displayProtocolsHelper() {
        // remove "multiple" from source select and select first source
        var $sourceSelect = $('#filterSourcesSelect'),
            $protocolButtons = $('#filterProtocolButtons');
        $sourceSelect.prop('multiple', false);

        // disable 'any' in sources
        $sourceSelect.find('option[value="any"]').prop('disabled', true).prop('selected', false);

        // select the first element
        $sourceSelect.find('option:not([disabled]):first').prop('selected', true);

        // protocol buttons become checkboxes and get checked by default
        $protocolButtons.find('label').removeClass('active').filter(function() { return $(this).find('input').val() !== 'any'}).addClass('active');
        $protocolButtons.find('label input').attr('type', 'checkbox').prop('checked', false).filter('[value!="any"]').prop('checked', true);
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

        // enable 'any' in sources
        $sourceSelect.find('option[value="any"]').prop('disabled', false);

        // uncheck protocol buttons and transform to radio buttons
        $protocolButtons.find('label').removeClass('active');
        $protocolButtons.find('label input').prop('checked', false).attr('type','radio');

        // select TCP proto as default
        $protocolButtons.find('label:first').addClass('active').find('input').prop('checked', true);

        // select all ports
        $portsSelect.find('option').prop('selected', true);
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