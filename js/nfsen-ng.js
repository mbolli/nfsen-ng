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

