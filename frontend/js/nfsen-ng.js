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
$(document).ready(function() {

    // get config from backend
    $.get('../api/config', function(data, status) {
        if (status === 'success') {
            config = data;
            init();
        } else {
            console.log('There probably was a problem with getting the config.');
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
        $content.each(showRightDiv);
    });

    // update date range slider
    // set time window or time slot
    $(document).on('change', 'input[name=singledouble]', function() {
        date_range.update({
            type: $(this).val()
        });
    });

    // set predefined time range
    $(document).on('change', 'input[name=range]', function() {
        date_range.update({
            from: date_range.options.to-$(this).val(),
            to: date_range.options.to,
        });
    });

    // update time range after source change
    $(document).on('change', '#graphFilterSourceSelection', function() {
        date_range.update({
            min: config['sources'][$(this).val()][0],
            max: config['sources'][$(this).val()][1]
        });
    });

    // initialize application
    function init() {

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

        var sources = Object.keys(config["sources"]);
        updateSources(sources);


        // todo modify for json coming from ../api/graph
        graph = new Dygraph(
            document.getElementById("flowDiv"),
            "csv/flows.csv",
            {
                title : 'Test Graph for time series : flows',
                //axisLabelFontSize : 15,
                ylabel : 'Flows',
                xlabel : 'Date / Time',
                visibility: [true, true, true, true, true],
                labelsKMB : true,
                labelsDiv : document.getElementById("flowStatusDiv"),
                labelsSeparateLines : true,
                legend : 'always',
                //stackedGraph : true,
                //logscale : true,
                showRangeSelector: true
            }
        );
    }
});


// todo make it work without IDs
function updateSources(sources) {
    var filterViewsIds =["#graphFilterSourceSelection","#flowsFilterSourceSelection","#statsFilterSourceSelection"];

    filterViewsIds.forEach(function(element){
        $.each(sources, function(key, value) {
            $(element)
                .append($("<option></option>")
                    .attr("value",value)
                    .text(value));
        });
    });
}
