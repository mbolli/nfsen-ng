var config,
    dygraph,
    dygraph_config,
    dygraph_data,
    dygraph_rangeselector_active,
    dygraph_daterange,
    dygraph_did_zoom,
    footable_data,
    date_range,
    date_range_interval,
    api_last_query,
    api_graph_options,
    api_flows_options,
    api_statistics_options,
    nfdump_translation = {ff: 'flow record flags in hex', ts: 'Start Time - first seen', te: 'End Time - last seen', tr: 'Time the flow was received by the collector', td: 'Duration', pr: 'Protocol', exp: 'Exporter ID', eng: 'Engine Type/ID', sa: 'Source Address', da: 'Destination Address', sap: 'Source Address:Port', dap: 'Destination Address:Port', sp: 'Source Port', dp: 'Destination Port', sn: 'Source Network (mask applied)', dn: 'Destination Network (mask applied)', nh: 'Next-hop IP Address', nhb: 'BGP Next-hop IP Address', ra: 'Router IP Address', sas: 'Source AS', das: 'Destination AS', nas: 'Next AS', pas: 'Previous AS', in: 'Input Interface num', out: 'Output Interface num', pkt: 'Packets - default input', ipkt: 'Input Packets', opkt: 'Output Packets', byt: 'Bytes - default input', ibyt: 'Input Bytes', obyt: 'Output Bytes', fl: 'Flows', flg: 'TCP Flags', tos: 'Tos - default src', stos: 'Src Tos', dtos: 'Dst Tos', dir: 'Direction: ingress, egress', smk: 'Src mask', dmk: 'Dst mask', fwd: 'Forwarding Status', svln: 'Src vlan label', dvln: 'Dst vlan label', ismc: 'Input Src Mac Addr', odmc: 'Output Dst Mac Addr', idmc: 'Input Dst Mac Addr', osmc: 'Output Src Mac Addr', pps: 'Packets per second', bps: 'Bytes per second', bpp: 'Bytes per packet', flP: 'Flows (%)', ipktP: 'Input Packets (%)', opktP: 'Output Packets (%)', ibytP: 'Input Bytes (%)', obytP: 'Output Bytes (%)', ipps: 'Input Packets/s', ibps: 'Input Bytes/s', ibpp: 'Input Bytes/Packet', pktP: 'Packets (%)', bytP: 'Bytes (%)'},
    views_view_status = {graphs: false, flows: false, statistics: false};

$(document).ready(function() {

    /**
     * get config from backend
     * example data:
     *
     *  config object {
     *    "sources": ["gate", "swi6"],
     *    "ports": [ 80, 23, 22 ],
     *    "stored_output_formats": [],
     *    "stored_filters": [],
     *    "daemon_running": true,
     *  }
     */
    $.get('../api/config', function(data, status) {
        if (status === 'success') {
            config = data;
            init();

            if (config.daemon_running === true) {

                var reload_seconds = 60;
                if (typeof config.frontend.reload_interval !== 'undefined') reload_seconds = config.frontend.reload_interval;

                display_message('info', 'Daemon is running, graph is reloading each ' + ((reload_seconds === 60) ? 'minute' : reload_seconds + ' seconds') + '.');

                date_range_interval = setInterval(function() {
                    if (date_range.options.max === date_range.options.to) {
                        var now = new Date();
                        date_range.update({ max: now.getTime(), to: now.getTime() });
                    }
                }, reload_seconds*1000);
            }
        } else {
            display_message('danger', 'Error getting the config!')
        }
    });

    /**
     * general ajax error handler
     */
    $(document).on('ajaxError', function(e, jqXHR) {
        console.log(jqXHR);
        if (typeof jqXHR === 'undefined') {
            display_message('danger', 'General error, please file a ticket on github!');
        } else if (typeof jqXHR.responseJSON === 'undefined') {
            display_message('danger', 'General error: ' + jqXHR.responseText);
        } else {
            display_message('danger', 'Got ' + jqXHR.responseJSON.error);
        }
    });

    /**
     * navigation functionality
     * show/hides the correct containers, which are identified by the data-view attribute
     */
    $(document).on('click', 'header li a', function(e) {
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

        // show the right divs
        $filter.each(showDivs);
        $content.each(showDivs);

        // re-initialize form
        if (view === 'graphs') $('#filterDisplaySelect').trigger('change');
        if (view === 'flows') $('#statsFilterForSelection').val('record').trigger('change');

        // trigger resize for the graph
        if (typeof dygraph !== 'undefined') dygraph.resize();

        // set defaults for the view
        init_defaults(view);

        // set view state to true
        views_view_status[view] = true;
    });

    /**
     * home-button functionality
     * reloads the page
     */
    $(document).on('click', 'header .reload', function(e) {
        e.preventDefault();
        window.location.reload(true);
    });

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
    $(document).on('change', '#date_slot input[name=range]', function() {
        var range = parseInt($(this).val());

        date_range.update({
            from: date_range.options.to - range,
            to: date_range.options.to // the current "to" value should stay
        });

        check_daterange_boundaries(range);
    });

    /**
     * sync button
     * gets the time range from the graph and updates the date range slider
     */
    $(document).on('click', '#date_syncing button.sync-date', function() {
        var from = dygraph_daterange[0].getTime(),
            to = dygraph_daterange[1].getTime();

        date_range.update({
            from: from,
            to: to
        });

        // remove active state of date slot button
        $('#date_slot').find('label.active').removeClass('active').find('input').prop('checked', false);

        check_daterange_boundaries(to-from);
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

        // initialize tooltips
        $('[data-toggle="tooltip"]').tooltip();

        // try to update graph
        updateGraph();
    });

    /**
     * protocols filter
     * reload the graph when the protocol selection changes
     */
    $(document).on('change', '#filterProtocols input', function() {
        var $filter = $('#filterProtocols');
        if ($(this).val() === 'any') {
            // uncheck all other input elements
            $(this).parent().addClass('active');
            $filter.find('input[value!="any"]').each(function () {
                $(this).prop('checked', false).parent().removeClass('active');
            });
        } else {
            // uncheck 'any' input element
            $filter.find('input[value="any"]').prop('checked', false).parent().removeClass('active');
        }

        // prevent having none checked - select 'any' as fallback
        if ($filter.find('input:checked').length === 0) {
            $filter.find('input[value="any"]').prop('checked', true).parent().addClass('active');
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
     * set graph display to curve or step plot
     */
    $(document).on('change', '#graph_lineplot input', function() {
        dygraph.updateOptions({
            stepPlot: ($(this).val() === 'step')
        });
    })

    /**
     * set graph display to lines or stacked
     */
    $(document).on('change', '#graph_linestacked input', function() {
        var stacked = ($(this).val() === 'stacked');

        dygraph.updateOptions({
            stackedGraph: ($(this).val() === 'stacked'),
            fillGraph: ($(this).val() !== 'line')
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
     * disable aggregation fields if statistics "for" field is not "flow records"
     */
    $(document).on('change', '#statsFilterForSelection', function() {
        var disabled = ($(this).val() !== 'record');

        $('#filterAggregation').find('label, input, select, button').each(function() {
            $(this).prop('disabled', disabled).toggleClass('disabled', disabled);
        });

        $('#filterOutputSelection').prop('disabled', disabled).toggleClass('disabled', disabled);
    });

    /**
     * Process flows/statistics form submission
     */
    $(document).on('click', '#filterCommands .submit', function() {
        var current_view = $(this).attr('data-view'),
            do_continue = true,
            date_diff = date_range.options.to-date_range.options.from,
            count_sources = $('#filterSourcesSelect').val().length;

        // warn user of long-running query
        if (date_diff*count_sources > 1000*24*60*60*12) {
            var count_days = parseInt(date_diff/1000/24/60/60),
                calc_info = count_days + ' days and ' + count_sources + ' sources';
            do_continue = confirm('Be aware that nfdump will scan 288 capture files per day and source. You selected ' + calc_info + '. This might take a long time and lots of server resources. Are you sure you want to submit this query?');
        }

        if (do_continue === false) return false;
        if (current_view === 'statistics') submit_statistics();
        if (current_view === 'flows') submit_flows();

        // remove success errors
        $('#error').find('div.alert-success').fadeOut(1500, function () {
            $(this).remove();
        });

        $(this).button('loading');

    });

    /**
     * Get a CSV of the currently selected data
     */
    $(document).on('click', '#filterCommands .csv', function() {
        $('#filterCommands .submit:visible').trigger('click');
        window.open(api_last_query + '&csv', '_blank');
    });

    /**
     * Reset flows/statistics form
     */
    $(document).on('click', '#filterCommands .reset', function() {
        var view = $('header').find('li.active a').attr('data-view'),
            $filter = $('#filterContainer');

        $filter.find('form').eq(0).trigger('reset');
        $filter.find('input:visible, textarea:visible, select:visible, button:visible').trigger('change');

    });

    /**
     * initialize the frontend
     * - set the select-list of sources
     * - initialize the range slider
     * - load the graph
     * - select default view if set in the config
     */
    function init() {
        // load default view
        if (typeof config.frontend.defaults.view !== 'undefined') {
            $('header li a[data-view="' + config.frontend.defaults.view + '"]').trigger('click');
        } else {
            $('header li a').eq(0).trigger('click');
        }

        // load values for form
        updateDropdown('sources', config['sources']);
        updateDropdown('ports', config['ports']);

        init_rangeslider();

        // show graph for one year by default
        $('#date_slot').find('[data-unit="y"]').parent().trigger('click');
    }

    /**
     * sets default values for the view (graphs, flows, statistics)
     * hides unneeded controls if e.g. only one source or one port is defined
     * @param view
     */
    function init_defaults(view) {
        if (typeof config.frontend.defaults !== 'undefined') {
            var defaults = config.frontend.defaults;
        }

        // graphs defaults
        if (view === 'graphs' && views_view_status.graphs === false) {
            // graphs: set default display (sources, protocols, ports)
            if (typeof defaults.graphs.display !== 'undefined') {
                $('#filterDisplaySelect').val(defaults.graphs.display).trigger('change');
            } else {
                $('#filterDisplaySelect').trigger('change');
            }

            // graphs: set default datatype
            if (typeof defaults.graphs.datatype !== 'undefined') {
                $('#filterTypes input[value="' + defaults.graphs.datatype + '"]').trigger('click');
            }

            // graphs: set default protocols
            if (typeof defaults.graphs.protocols !== 'undefined') {
                // multiple possible if on protocols display
                if (defaults.graphs.display === 'protocols') {
                    $('#filterProtocolButtons input[value="any"]').trigger('click');
                    $.each(defaults.graphs.protocols, function (i, proto) {
                        $('#filterProtocolButtons input[value="' + proto + '"]').trigger('click');
                    });
                } else {
                    $('#filterProtocolButtons input[value="' + defaults.graphs.protocols[0] + '"]').trigger('click');
                }
            }

            // graphs: hide unneeded controls
            if (config['sources'].length == 1) { // only one source defined
                $('#filterDisplaySelect option[value="sources"]').remove();
                $('#filterSources').hide();
            }

            if (config['ports'].length == 0) { // only one port defined
                $('#filterDisplaySelect option[value="ports"]').remove();
            }

            if ($('#filterDisplaySelect option').length == 1) { // only one display option left
                $('#filterDisplay').hide();
            }
        }

        // flows defaults
        if (view === 'flows' && views_view_status.flows === false) {

            // flows: limit
            if (typeof defaults.flows.limit !== 'undefined') {
                $('#flowsFilterLimitSelection').val(defaults.flows.limit);
            }
        }

        // statistics defaults
        if (view === 'statistics' && views_view_status.statistics === false) {

            // statistics: order by
            if (typeof defaults.statistics.orderby !== 'undefined') {
                $('#statsFilterOrderBySelection').val(defaults.statistics.orderby);
            }
        }

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

                // deactivate syncing button
                $('#date_syncing').find('button.sync-date').prop('disabled', true);

                updateGraph();
            },
            onUpdate: function(data) {
                dygraph_daterange = [new Date(data.from), new Date(data.to)];

                // deactivate syncing button
                $('#date_syncing').find('button.sync-date').prop('disabled', true);

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

                // activate syncing button
                $('#date_syncing').find('button.sync-date').prop('disabled', false);

                // update graph
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
        if ($('#flowDiv:visible').length === 0) return;
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
        if (elements.length > 4) title += elements.length + ' ' + cat;
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
                        stepPlot: true,
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
                display_message('warning', 'There somehow was a problem getting data, please check your form values.');
            }
        });
    }

    /**
     * Display a message in the frontend
     * @param severity (success, info, warning, danger)
     * @param message
     */
    function display_message(severity, message) {
        var current_view = $('header').find('li.active a').attr('data-view'),
            $error = $('#error'),
            $buttons = $('button[data-loading-text][data-view=' + current_view +']'),
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

        // empty data table
        $('#contentDiv').find('table.table').empty();
    }

    /**
     * checks if with supplied date range, navigation is still possible (e.g. plus 1 month)
     * and disables navigation buttons if not
     * @param range date difference in milliseconds
     */
    function check_daterange_boundaries(range) {
        var $buttons = $('#date_slot_nav').find('button');

        // reset next/prev buttons (depending on selected range)
        $buttons.filter('.next').prop('disabled', (date_range.options.to + range > date_range.options.max));
        $buttons.filter('.prev').prop('disabled', (date_range.options.from - range < date_range.options.min));
    }

    /**
     * Process flows form submission
     */
    function submit_flows() {
        var sources = $('#filterSourcesSelect').val(),
            datestart = parseInt(dygraph_daterange[0].getTime()/1000),
            dateend = parseInt(dygraph_daterange[1].getTime()/1000),
            filter = '' + $('#filterNfdumpTextarea').val(),
            limit = $('#flowsFilterLimitSelection').val(),
            sort = '',
            output = {
                format: $('#filterOutputSelection').val(),
                custom: $('#customListOutputFormatValue').val()
            };

        // parse form values to generate a proper API request
        var aggregate = parse_aggregation_fields();

        if (typeof sources === 'string') sources = [sources];

        if ($('#flowsFilterOther').find('[name=ordertstart]:checked').length > 0) {
            sort = $('[name=ordertstart]:checked').val();
        }

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

        api_last_query = '../api/flows/?' + $.param( api_flows_options );
        var req = $.get('../api/flows', api_flows_options, render_table);
    }


    /**
     * Process statistics form submission
     */
    function submit_statistics() {
        var sources = $('#filterSourcesSelect').val(),
            datestart = parseInt(dygraph_daterange[0].getTime() / 1000),
            dateend = parseInt(dygraph_daterange[1].getTime() / 1000),
            filter = '' + $('#filterNfdumpTextarea').val(),
            top = $('#statsFilterTopSelection').val(),
            s_for = $('#statsFilterForSelection').val(),
            title = $('#statsFilterForSelection :selected').text(),
            sort = $('#statsFilterOrderBySelection').val(),
            fmt = $('#filterOutputSelection'),
            output = {};

        if (!fmt.prop('disabled')) {
            output.format = fmt.val();
            output.custom = $('#customListOutputFormatValue').val();
        }

        // parse form values to generate a proper API request
        var aggregate = parse_aggregation_fields();

        if (typeof sources === 'string') sources = [sources];

        api_statistics_options = {
            datestart: datestart,
            dateend: dateend,
            sources: sources,
            filter: filter,
            top: top,
            for: s_for + '/' + sort,
            title: title,
            aggregate: aggregate,
            limit: '',
            output: output
        };

        api_last_query = '../api/stats/?' + $.param( api_statistics_options );
        var req = $.get('../api/stats', api_statistics_options, render_table);
    }

    /**
     * Parse aggregation fields and return something meaningful, e.g. proto,srcip/24
     * @returns string
     */
    function parse_aggregation_fields() {
        var $aggregation = $('#filterAggregation');
        if ($aggregation.find('[name=bidirectional]:checked').length === 0) {
            var validAggregations = ['proto', 'dstport', 'srcport', 'srcip', 'dstip'],
                aggregate = '';

            $.each(validAggregations, function(id, val) {
                if ($aggregation.find('[name=' + val + ']:checked').length > 0) {
                    aggregate += (aggregate === '') ? val : ',' + val;
                } else {
                    var select = $aggregation.find('[name=' + val + ']').val();
                    if (select === 'none') return;
                    if (val === 'srcip') {
                        var prefix = parseInt($aggregation.find('[name=srcipprefix]:visible').val()),
                            srcprefix = (isNaN(prefix) || prefix === 'srcip') ? '' : '/' + prefix,
                            srcip = select + srcprefix;
                        aggregate += (aggregate === '') ? srcip : ',' + srcip;
                    } else if (val === 'dstip') {
                        var prefix = parseInt($aggregation.find('[name=dstipprefix]:visible').val()),
                            dstprefix = (isNaN(prefix) || prefix === 'dstip') ? '' : '/' + prefix,
                            dstip = select + dstprefix;
                        aggregate += (aggregate === '') ? dstip : ',' + dstip;
                    }
                }
            });

            return aggregate;

        } else return 'bidirectional';
    }

    /**
     * parses the provided data, converts it into a better suitable format and populates a html table
     * @param data
     * @param status
     * @returns boolean
     */
    function render_table(data, status) {
        if (status === 'success') {
            footable_data = data;

            // print nfdump command
            if (typeof data[0] === 'string') {
                display_message('success', 'nfdump command: ' + data[0].toString())
            }

            // return if invalid data got returned
            if (typeof data[1] !== 'object') {
                display_message('warning', 'something went wrong. ' + data[1].toString());
                return false;
            }

            // generate table header
            var tempcolumns = data[1],
                columns = [];

            $.each(tempcolumns, function (i, val) {
                // todo optimize breakpoints
                var title = (val === 'val') ? api_statistics_options.title : nfdump_translation[val],
                    column = {
                        name: val,
                        title: title,
                        type: 'number',
                        breakpoints: 'xs sm',
                    };
                if (['ts', 'te', 'tr'].indexOf(val) !== -1) {
                    column['breakpoints'] = '';
                    column['type'] = 'text'; // 'date' needs moment.js library...
                } else if (['sp', 'dp', 'td', 'fl', 'flP', 'ipktP', 'opktP', 'ibytP', 'obytP', 'ipps', 'opps', 'ibps', 'obps', 'ibpp', 'obpp', 'pktP', 'bytP'].indexOf(val) !== -1) {
                    column['type'] = 'number';
                    column['thousandSeparator'] = '\'';
                } else if (['sa', 'da', 'pr', 'val'].indexOf(val) !== -1) {
                    column['breakpoints'] = '';
                    column['type'] = 'text';
                } else if (['dtos', 'stos', 'tos', 'ipkt', 'opkt', 'ibyt', 'obyt', 'fl'].indexOf(val) !== -1) {
                    column['breakpoints'] = 'xs sm md';
                } else if (['flg', 'fwd', 'in', 'out', 'sas', 'das'].indexOf(val) !== -1) {
                    column['breakpoints'] = 'all';
                    column['type'] = 'text';
                } else {
                    column['breakpoints'] = '';
                    column['type'] = 'text';
                }
                columns.push(column);
            });

            // generate table data
            var temprows = data.slice(2),
                rows = [];

            $.each(temprows, function (i, val) {
                var row = {id: i};

                $.each(val, function (j, col) {
                    row[tempcolumns[j]] = col;
                });

                rows.push(row);
            });

            // init footable
            $('table.table:visible').footable({
                columns: columns,
                rows: rows
            });

            if (rows.length > 0) $('table.table:visible .footable-empty').remove();

            // remove errors (except success)
            $('#error').find('div.alert:not(.alert-success)').fadeOut(1500, function () {
                $(this).remove();
            });
        }

        // reset button label
        $('#filterCommands').find('.submit').button('reset');
    }


    /**
     * hide or show the custom output filter
     */
    $(document).on('change', '#filterOutputSelection', function() {

        // if "custom" is selected, show "customFlowListOutputFormat" otherwise hide it
        if ($(this).val() === 'custom') $('#customListOutputFormat').removeClass('hidden');
        else $('#customListOutputFormat').addClass('hidden');
    });

    /**
     * block not available options on "bi-direction" checked
     */
    $(document).on('change', '#filterAggregationGlobal input[name=bidirectional]', function() {
        var $filterAggregation = $('#filterAggregation');

        // if "bi-directional" is checked, block (disable) all other aggregation options
        if ($(this).parent().hasClass('active')) {

            $filterAggregation.find('[data-disable-on="bi-directional"]').each(function() {
                $(this).parent().removeClass('active').addClass('disabled');
                $(this).prop('disabled', true);
                if ($(this).prop('tagName') === 'SELECT') $(this).prop('selectedIndex', 0);
                else $(this).val('');
            });

        } else {

            $filterAggregation.find('[data-disable-on="bi-directional"]').each(function() {
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
            $prefixDiv = $('#' + kind + 'CIDRPrefixDiv');

        switch ($(this).val()) {
            case 'none':
            case 'srcip':
            case 'dstip':
                $prefixDiv.addClass('hidden');
                break;
            case 'srcip4':
            case 'dstip4':
                $prefixDiv.removeClass('hidden');
                $prefixDiv.find('input').attr('maxlength', 2).val('');
                break;
            case 'srcip6':
            case 'dstip6':
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
