// A $( document ).ready() block.
$( document ).ready(function() {
    console.log( "ready!" );
});

// Variables
var availableSources = [];
var graphObject = null;
var earliestDate = null;
var latestDate = null;



function changeActiveView(el)
{

    if(el.id == "viewGraphsLink")
    {
        $("#flowsFilterDiv").hide();
        $("#statsFilterDiv").hide();
        $("#graphsFilterDiv").show();

        $("#flowsContectDiv").hide();
        $("#statsContentDiv").hide();
        $("#graphsContentDiv").show();
    }
    if(el.id == "viewFlowsLink")
    {
        $("#graphsFilterDiv").hide();
        $("#statsFilterDiv").hide();
        $("#flowsFilterDiv").show();

        $("#graphsContentDiv").hide();
        $("#statsContentDiv").hide();
        $("#flowsContentDiv").show();
    }
    if(el.id == "viewStatsLink")
    {
        $("#graphsFilterDiv").hide();
        $("#flowsFilterDiv").hide();
        $("#statsFilterDiv").show();

        $("#graphsContentDiv").hide();
        $("#flowsContentDiv").hide();
        $("#statsContentDiv").show();
    }
}

function getInitialAppDataFromServer()
{
    // should be surrounded by try and catch (do something if API does not answer)

    // temporary populates with static data
    availableSources = ["swibi","tiber","swibe"]; // for testing purposes only
}

function setInitialAppData()
{
    // set sources for all filter views (by id)
    updateSources(availableSources);
    createGraph(); //this line must be deleted



}


// Helper methods START

function updateSources(sources)
{
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

function createGraph() // this function has been taken out of html file, but should be modified as soon as API is available
{
    fg = new Dygraph(
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





// Helper methods END





// TEST METHODS START


// Autopopulate Sources
(function () {getInitialAppDataFromServer();console.log(availableSources)})();
(function () {setInitialAppData();console.log("initial se app data done")})();


// TEST METHODS END