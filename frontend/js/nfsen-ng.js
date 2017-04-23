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

        $('header a').removeClass('active');
        $(this).addClass('active');

        var showRightDiv = function(id, el) {
            if ($(el).attr('data-view') === view) $(el).show();
            else $(el).hide();
        };

        $filter.each(showRightDiv);
        $content.each(showRightDiv);
    });

    // initialize application
    function init() {
        var sources = Object.keys(config["sources"]);
        updateSources(sources);

        // todo modify for json coming from ../api/graph
        var fg = new Dygraph(
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
