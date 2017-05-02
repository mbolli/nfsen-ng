/*
 config object {
    "sources": {
        "gate": [false,false], // timestamp start, timestamp end
        "swi6": [false,false]
    },
    "stored_output_formats":[],
    "stored_filters":[]
 }
 */
var config;
var date_range;
var graph;
var api_graph_options;

$(document).ready(function() {

    /*Get config from backend by sending a HTTP GET request to the API.
    * The data that is returned contains the config needed by the frontend.
    * The config contains ??? need to discuss with bollm6, don't get the woodoo magic :-)
    * */
    $.get('../api/config', function(data, status) {
        if (status === 'success') {
            config = data;
            init();
        } else {
            console.log('There was probably a problem with getting the config.');
            // todo consequences?
        }
    });

    // navigation
    $(document).on('click', 'header a', function() {
        var view = $(this).attr('data-view');
        var $filter = $('#filterDiv').find('div.filter');
        var $content = $('#contentDiv').find('div.content');

        $('header li').removeClass('active');
        $(this).parent().addClass('active');

        var showRightDiv = function(id, el) {
            if ($(el).attr('data-view') === view) $(el).show();
            else $(el).hide();
        };

        $filter.each(showRightDiv);
        $content.each(showRightDiv); /* todo put filter and content in same div? // galld2 2017_05_01 :
                                        good idea but if we need to be very careful because of some fix id's*/
    });

    // DATE RANGE SLIDER
    // set time window or time slot
    $(document).on('change', 'input[name=singledouble]', function() {
        date_range.update({
            type: $(this).val()
        });
    });

    // set predefined time range like day/week/month/year
    $(document).on('change', 'input[name=range]', function() {
        date_range.update({
            from: date_range.options.to-$(this).val(),
            to: date_range.options.to,
        });
    });

    // SOURCES
    $(document).on('change', '#graphFilterSourceSelection', function() {
        var sources = $(this).val(), max = getLastDate(sources), min = getFirstDate(sources);

        // update time range
        date_range.update({
            min: min,
            max: max
        });

        // update graph
        updateGraph();
    });

    // PROTOCOLS
    $(document).on('change', '#graphsFilterProtocolDiv input', updateGraph);

    // DATA TYPES
    $(document).on('change', '#graphsFilterDataTypeDiv input', updateGraph);

    // GRAPH VIEW
    $(document).on('change', '#curves input', function(e) {
        var $checkbox = $(e.target);
        dygraph.setVisibility($checkbox.parent().index(), $($checkbox).is(':checked'));
    });

    // initialize application
    function init() {

        // check if we have a config
        // todo probably a red half-transparent overlay over the whole page?
        if (typeof config !== 'object') console.log('Could not read config!');
        updateSources(Object.keys(config['sources']));

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
            min: 1482828600000, // todo set dates with some logic
            max: 1490604300000,
            force_edges: true,
            drag_interval: true,
            prettify: function(ut) {
                var date = new Date(ut);
                return date.toDateString();
            },
            onFinish: function(data) {
                console.log(data);
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
        $rangeEl.off('mousedown touchstart');

        // install new mouse down handler
        $rangeEl.on('mousedown touchstart', function () {

            // track that mouse is down on range selector
            dygraph_rangeselector_active = true;

            // setup mouse up handler to initiate new data load
            $(window).off('mouseup touchend'); //cancel any existing
            $(window).on('mouseup touchend', function () {
                $(window).off('mouseup touchend');

                // mouse no longer down on range selector
                dygraph_rangeselector_active = false;

                // get the new detail window extents
                dygraph_daterange = dygraph.xAxisRange();
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
            $(window).off('mouseup touchend'); //Cancel current event handler if any
            updateGraph();
            return;
        }

        //The zoom callback is called when zooming via mouse drag on graph area, as well as when
        //dragging the range selector bars. We only want to initiate dataload when mouse-drag zooming. The mouse
        //up handler takes care of loading data when dragging range selector bars.
        if (!dygraph_rangeselector_active)
            updateGraph();

    }

    /**
     * reads options from api_graph_options, performs a request on the API
     * and tries to display the received data in the graph.
     */
    function updateGraph() {
        var sources = $('#graphFilterSourceSelection').val(),
            type = $('#graphsFilterDataTypeDiv input:checked').val(),
            protos = $('#graphsFilterProtocolDiv').find('input:checked').map(function() { return $(this).val(); }).get(),
            title = type + ' for ';

        // check if options valid to request new dygraph
        if (protos.length === 0 || sources.length === 0) return;
        if (protos.length > 1 && sources.length > 1) return; // todo annotate wrong input?

        // set options
        api_graph_options = {
            datestart: dygraph_daterange ? parseInt(dygraph_daterange[0]/1000) : getFirstDate(sources)/1000,
            dateend: dygraph_daterange ? parseInt(dygraph_daterange[1]/1000) : getLastDate(sources)/1000,
            type: type,
            protocols: protos,
            sources: sources,
        };

        // set title
        if (protos.length >= sources.length) title += 'protocols ' + protos.join(', ') + ' (' + sources[0] + ')';
        else title += 'sources ' + sources.join(', ') + ' (' + protos[0] + ')';

        $.get('../api/graph', api_graph_options, function (data, status) {
            if (status === 'success') {

                // transform data to something Dygraph understands
                var dygraph_data = [];
                var labels = ['Date'];

                // iterate over labels
                $('#curves').empty();
                $.each(data.legend, function (id, legend) {
                    labels.push(legend);

                    $('#curves').append('<label><input type="checkbox" checked> ' + legend + '</label>');
                });

                // iterate over values
                $.each(data.data, function (datetime) {
                    var position = [new Date(datetime * 1000)];

                    $.each(this, function (y, val) {
                        position.push(val);
                    });

                    dygraph_data.push(position);
                });

                if (typeof dygraph === 'undefined') {
                    // initial dygraph config:
                    dygraph_config = {
                        title: title,
                        labels: labels,
                        ylabel: type.toUpperCase(),
                        xlabel: 'TIME',
                        labelsKMB: true,
                        labelsDiv: $('#legend')[0],
                        labelsSeparateLines: true,
                        legend: 'always',
                        showRangeSelector: true,
                        dateWindow: [dygraph_data[0][0], dygraph_data[dygraph_data.length - 1][0]],
                        zoomCallback: dygraph_zoom
                        // todo add current values of logscale, stackedGraph and fillGraph // galld2 comment, not needed, all false by default on load
                    };
                    dygraph = new Dygraph($('#flowDiv')[0], dygraph_data, dygraph_config);
                } else {
                    // update dygraph config
                    dygraph_config = {
                        // series: series,
                        // axes: axes,
                        labels: labels,
                        file: dygraph_data,
                        dateWindow: [dygraph_data[0][0], dygraph_data[dygraph_data.length - 1][0]],
                    };
                    dygraph.updateOptions(dygraph_config);

                }
                init_dygraph_mods();

            } else {
                console.log('There was probably a problem with getting dygraph data.');
                // todo consequences?
            }
        });
    }

    function getLastDate(sources) {
        var max = 0;
        $.each(sources, function(id, source) {
            var currentEnd = config['sources'][source][1]*1000;
            if (max === 0 || max < currentEnd) max = currentEnd;
        });
        return max;
    }

    function getFirstDate(sources) {
        var min = 0;
        $.each(sources, function(id, source) {
            var currentStart = config['sources'][source][0]*1000;
            if (min === 0 || min > currentStart) min = currentStart;
        });
        return min;
    }
});

function updateSources(sources) {

    var filterViewsDivSelects = document.querySelectorAll("#filterDiv div select");

    for (var i = 0; i < filterViewsDivSelects.length; i++)
    {
        if (filterViewsDivSelects[i].hasAttribute("data-filter-type"))
            {
                $.each(sources, function(key, value) {
                    $(filterViewsDivSelects[i])
                        .append($("<option></option>")
                            .attr("value",value)
                            .attr("selected", "selected")
                            .text(value));
                })
            }

    }
}

function adaptScaleToSelection(el) {
    if(el.id=="logarithmicScaleBtn")
    {
        graph.updateOptions({
            logscale : true
        });
    }
    else
    {
        graph.updateOptions({
            logscale : false
        });
    }

}

function adaptGraphTypeToSelection(el) {
    if(el.id=="stackedGraphBtn")
    {
        graph.updateOptions({
            stackedGraph : true,
            fillGraph: true
        });
    }
    else
    {
        graph.updateOptions({
            stackedGraph : false,
            fillGraph: false
        });
    }

}

function adaptGraphRollPeriodToSelection(el) {
    if(el.value > 0)
    {
        graph.adjustRoll(el.value);
    }
    else
    {
        graph.adjustRoll(0);
    }
}
