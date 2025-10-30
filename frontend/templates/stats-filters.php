<?php
/**
 * Statistics View Filters Component
 * Filter controls for the statistics view.
 */

use mbolli\nfsen_ng\common\Config;

// Get sources and default filters from config
$sources = Config::$cfg['general']['sources'] ?? [];
$defaultFilters = Config::$cfg['general']['filters'] ?? [];
?>

<div class="row"
     data-signals:stats.for="'record'"
     data-signals:stats.order-by="'flows'"
     data-signals:stats.top="10"
     data-signals:stats.nfdump_filter=""
     data-signals:stats.output-format="'line'"
     data-signals:stats.custom-output-format=""
     data-signals:stats.show-custom-format="false"
     data-signals:stats.show-output-format="false">

    <div id="filterStatisticsTop" class="col-xs-6 col-sm-2 col-md-2 col-lg-1" data-view="statistics">
        <p class="h6">Top records <nfsen-tooltip text="Number of top N statistics to display. Uses -n option in nfdump." placement="top"></nfsen-tooltip></p>
        <div class="form-group">
            <select data-bind:stats.count class="form-control form-select">
                <option value="10" selected>10</option>
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200">200</option>
                <option value="500">500</option>
            </select>
        </div>
    </div>
    <div id="filterSources" class="col-xs-6 col-sm-3 col-md-2">
        <p class="h6">Sources <nfsen-tooltip text="NetFlow data sources to query. Multiple sources are merged using -M option." placement="top"></nfsen-tooltip></p>

        <div class="form-group">
            <select data-bind:stats.sources multiple class="form-control form-select">
                <option value="any">Any</option>
                <?php foreach ($sources as $source) { ?>
                    <option value="<?php echo htmlspecialchars($source); ?>" selected><?php echo htmlspecialchars($source); ?></option>
                <?php } ?>
            </select>
        </div>
    </div>

    <div class="col-xs-12 col-sm-7 col-md-5">

        <p class="h6">NFDUMP filter <nfsen-tooltip text="The filter syntax is similar to tcpdump. Examples: 'dst port 80', 'packets > 1000', 'bytes > 10M' (supports k/M/G/T suffixes)" placement="right"></nfsen-tooltip></p>

        <div class="form-group">
            <textarea class="form-control" id="filterNfdumpTextareaStats" data-bind:stats.nfdump_filter rows="3" autocomplete="on"></textarea>
        </div>

        <nfsen-filter-manager
            data-target-textarea="filterNfdumpTextareaStats"
            data-default-filters='<?php echo json_encode($defaultFilters); ?>'>
        </nfsen-filter-manager>

    </div>

    <div id="filterStatsProperties" class="col-xs-6 col-sm-6 col-md-4 col-lg-2">

        <p class="h6">Statistic properties <nfsen-tooltip text="Generate statistics using -s option. Choose what to aggregate (IP, port, AS, etc.) and how to sort (flows, packets, bytes, rate)." placement="top"></nfsen-tooltip></p>

        <div class="form-group">
            <label for="statsFilterForSelection">Statistic for</label>
            <select id="statsFilterForSelection" class="form-control form-select" data-bind:stats.for>
                <option value="record" selected>Flow Records</option>
                <option value="ip">Any IP address</option>
                <option value="srcip">Src IP address</option>
                <option value="dstip">Dst IP address</option>
                <option value="port">Any port</option>
                <option value="srcport">Src port</option>
                <option value="dstport">Dst port</option>
                <option value="if">Any interface</option>
                <option value="inif">IN interface</option>
                <option value="outif">OUT interface</option>
                <option value="as">Any AS</option>
                <option value="srcas">Src AS</option>
                <option value="dstas">Dst AS</option>
                <option value="nhip">Next Hop IP</option>
                <option value="nhbip">Next Hop BGP IP</option>
                <option value="router">Router IP</option>
                <option value="proto">Proto</option>
                <option value="dir">Direction</option>
                <option value="srctos">Src TOS</option>
                <option value="dsttos">Dst TOS</option>
                <option value="tos">Tos</option>
                <option value="mask">Any Mask Bits</option>
                <option value="srcmask">Src Mask Bits</option>
                <option value="dstmask">Dst Mask Bits</option>
                <option value="vlan">Any VLAN ID</option>
                <option value="srcvlan">Src VLAN ID</option>
                <option value="dstvlan">Dst VLAN ID</option>
                <option value="srcmac">Src MAC</option>
                <option value="dstmac">Dst MAC</option>
                <option value="inmac">IN MAC</option>
                <option value="outmac">OUT MAC</option>
                <option value="insrcmac">IN src MAC</option>
                <option value="outdstmac">OUT dst MAC</option>
                <option value="indstmac">IN dst MAC</option>
                <option value="outsrcmac">OUT src MAC</option>
                <option value="mpls1">MPLS Label 1</option>
                <option value="mpls2">MPLS Label 2</option>
                <option value="mpls3">MPLS Label 3</option>
                <option value="mpls4">MPLS Label 4</option>
                <option value="mpls5">MPLS Label 5</option>
                <option value="mpls6">MPLS Label 6</option>
                <option value="mpls7">MPLS Label 7</option>
                <option value="mpls8">MPLS Label 8</option>
                <option value="mpls9">MPLS Label 9</option>
                <option value="mpls10">MPLS Label 10</option>
            </select>
        </div>

        <div class="form-group">
            <label for="statsFilterOrderBySelection">Order by</label>
            <select id="statsFilterOrderBySelection" class="form-control form-select" data-bind:stats.order-by>
                <option value="flows" selected>Flows</option>
                <option value="packets">Packets</option>
                <option value="bytes">Bytes</option>
                <option value="pps">Packets per second</option>
                <option value="bps">Bits per second</option>
                <option value="bpp">Bytes per packet</option>
            </select>
        </div>

    </div><!-- statsFilterPropertiesDiv  -->

    <!-- Commands Section -->
    <div class="col-12 border-top mt-3 pt-3">
            <nfsen-tooltip text="Generate statistics using nfdump -s option with current filters" placement="top" no-icon>
                <button type="button"
                        class="btn btn-primary"
                        data-indicator:stats.indicator
                        data-on:click="@post('/api/stats-actions', {action: 'processStatistics'})">
                    <span data-show="!$stats.indicator">Process data</span>
                    <span class="spinner-grow spinner-grow-sm" data-show="$stats.indicator" role="status" aria-hidden="true"></span>
                    <span data-show="$stats.indicator">Processing...</span>
                </button>
            </nfsen-tooltip>
        </div>
    </div>
</div><!-- statisticsView  -->
