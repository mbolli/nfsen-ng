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
        graph.setVisibility($checkbox.parent().index(), $($checkbox).is(':checked'));
    });

    // initialize application
    function init() {

        // initialize date range slider
        $('#date_range').ionRangeSlider({
            type: 'double',
            grid: true,
            min: 1482828600000,
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

        // check if we have a config
        // todo probably a red half-transparent overlay over the whole page?
        if (typeof config !== 'object') console.log('Could not read config!');

        updateSources(Object.keys(config["sources"]));
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

        // check if options valid to request new graph
        if (protos.length === 0 || sources.length === 0) return;
        if (protos.length > 1 && sources.length > 1) return; // todo annotate wrong input?

        // set options
        api_graph_options = {
            datestart: getFirstDate(sources)/1000,
            dateend: getLastDate(sources)/1000,
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
                $.each(data.legend, function(id, legend) {
                    labels.push(legend);

                    $('#curves').append('<label><input type="checkbox" checked> ' + legend + '</label>');
                });

                // iterate over values
                $.each(data.data, function(datetime) {
                    var position = [ new Date(datetime*1000) ];

                    $.each(this, function(y, val) {
                        position.push(val);
                    });

                    dygraph_data.push(position);
                });

                graph = new Dygraph(
                    $('#flowDiv')[0],
                    dygraph_data, {
                        title : title,
                        labels: labels,
                        ylabel : type.toUpperCase(),
                        xlabel : 'TIME',
                        labelsKMB : true,
                        labelsDiv : $('#legend')[0],
                        labelsSeparateLines : true,
                        legend : 'always',
                        showRangeSelector: true,
                        dateWindow: [dygraph_data[0][0], dygraph_data[dygraph_data.length-1][0]],
                        // todo add current values of logscale, stackedGraph and fillGraph // galld2 comment, not needed, all false by default on load
                    }
                );
            } else {
                console.log('There was probably a problem with getting graph data.');
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
