<?php
/**
 * Flows View Filters Component
 * Filter controls for the flows view.
 */

use mbolli\nfsen_ng\common\Config;

// Get sources and default filters from config
$sources = Config::$cfg['general']['sources'] ?? [];
$defaultFilters = Config::$cfg['general']['filters'] ?? [];
?>

<div class="row"
     data-signals:flows.aggregation='{"bidirectional": false, "proto": false, "srcport": false, "dstport": false, "srcip": "none", "srcipPrefix": "", "dstip": "none", "dstipPrefix": ""}'
     data-signals:flows.nfdump_filter=""
     data-signals:flows.limit="50"
     data-signals:flows.output-format="'line'"
     data-signals:flows.custom-output-format=""
     data-signals:flows.order-by-tstart="false"
     data-signals:flows.show-source-cidr="false"
     data-signals:flows.show-dest-cidr="false"
     data-signals:flows.show-custom-format="false">

    <div id="filterFlowsLimit" class="col-auto" data-view="flows">
        <p class="h6">Limit Flows <nfsen-tooltip text="Maximum number of flow records to display. Use -c option in nfdump." placement="top"></nfsen-tooltip></p>

        <div class="form-group">
            <select class="form-control form-select" data-bind:flows.limit>
                <option value="20">20</option>
                <option value="50" >50</option>
                <option value="100" >100</option>
                <option value="500" >500</option>
                <option value="1000" >1000</option>
                <option value="10000" >10000</option>
            </select>
        </div>
    </div>

    <div id="filterSources" class="col-auto">
        <p class="h6">Sources <nfsen-tooltip text="NetFlow data sources to query. Multiple sources are merged using -M option." placement="top"></nfsen-tooltip></p>

        <div class="form-group">
            <select data-bind:flows.sources multiple class="form-control form-select">
                <option value="any">Any</option>
                <?php foreach ($sources as $source) { ?>
                    <option value="<?php echo htmlspecialchars($source); ?>" selected><?php echo htmlspecialchars($source); ?></option>
                <?php } ?>
            </select>
        </div>
    </div>

    <div class="col-xs-12 col-sm-12 col-md-auto col-lg-auto">

        <p class="h6">NFDUMP filter <nfsen-tooltip text="The filter syntax is similar to the well known pcap library used by tcpdump. All keywords are case-independent. Example: dst port 80" placement="right"></nfsen-tooltip></p>

        <div class="form-group">
            <textarea class="form-control" id="filterNfdumpTextarea" data-bind:flows.nfdump_filter rows="3" autocomplete="on"></textarea>
        </div>

        <nfsen-filter-manager
            data-target-textarea="filterNfdumpTextarea"
            data-default-filters='<?php echo json_encode($defaultFilters); ?>'>
        </nfsen-filter-manager>

    </div>

    <div id="filterFlowAggregation" class="col-12 col-xl-auto">
        <p class="h6 mb-2">Aggregation & Output <nfsen-tooltip text="Aggregate flows using -a option. Bi-directional: -b, Protocol: omit proto, IP: aggregate by network prefix, Order: sort by first seen time." placement="top"></nfsen-tooltip></p>
        <div class="row g-2" data-view="flows">
            <!-- Global & Port Aggregation -->
            <div class="col-auto">
                <label class="form-label small mb-1">Global</label>
                <div class="btn-group btn-group-sm d-flex mb-2" data-bs-toggle="buttons" id="filterFlowAggregationGlobal">
                    <input type="checkbox" class="btn-check" name="bidirectional" data-bind:flows.aggregation.bidirectional id="filterFlowAggregationGlobalBi">
                    <label class="btn btn-outline-primary" for="filterFlowAggregationGlobalBi">Bi-directional</label>
                    <input data-disable-on="bi-directional" type="checkbox" class="btn-check" name="proto" data-bind:flows.aggregation.proto id="filterFlowAggregationGlobalProto">
                    <label class="btn btn-outline-primary" for="filterFlowAggregationGlobalProto">Protocol</label>
                </div>
                <label class="form-label small mb-1">Port</label>
                <div class="btn-group btn-group-sm d-flex" data-bs-toggle="buttons" id="filterFlowAggregationPort">
                    <input data-disable-on="bi-directional" type="checkbox" class="btn-check" name="srcport" id="filterFlowAggregationPortSrc" data-bind:flows.aggregation.srcport>
                    <label class="btn btn-outline-primary" for="filterFlowAggregationPortSrc">Source</label>
                    <input data-disable-on="bi-directional" type="checkbox" class="btn-check" name="dstport" id="filterFlowAggregationPortDst" data-bind:flows.aggregation.dstport>
                    <label class="btn btn-outline-primary" for="filterFlowAggregationPortDst">Destination</label>
                </div>
            </div>

            <!-- IP Aggregation -->
            <div class="col-auto">
                <label class="form-label small mb-1">IP Aggregation</label>
                <div class="row g-1">
                    <div class="col-6">
                        <select data-attr:disabled="$flows.aggregation.bidirectional" data-kind="source" name="srcip" class="form-select form-select-sm" data-bind:flows.aggregation.srcip>
                            <option value="none">Src: None</option>
                            <option value="srcip">Src: IP</option>
                            <option value="srcip4">Src: IPv4 /</option>
                            <option value="srcip6">Src: IPv6 /</option>
                        </select>
                        <div class="subnet-input">
                            <input data-attr:disabled="$flows.aggregation.srcip == 'srcip' || $flows.aggregation.srcip == 'none'" placeholder="24" name="srcipprefix" type="text" class="form-control form-control-sm mt-1" style="width:3.5rem" data-bind:flows.aggregation.srcip-prefix>
                        </div>
                    </div>
                    <div class="col-6">
                        <select data-attr:disabled="$flows.aggregation.bidirectional" data-kind="destination" name="dstip" class="form-select form-select-sm" data-bind:flows.aggregation.dstip>
                            <option value="none">Dst: None</option>
                            <option value="dstip">Dst: IP</option>
                            <option value="dstip4">Dst: IPv4 /</option>
                            <option value="dstip6">Dst: IPv6 /</option>
                        </select>
                        <div class="subnet-input">
                            <input data-attr:disabled="$flows.aggregation.dstip == 'dstip' || $flows.aggregation.dstip == 'none'" placeholder="24" name="dstipprefix" type="text" class="form-control form-control-sm mt-1" style="width:3.5rem" data-bind:flows.aggregation.dstip-prefix>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Output Options -->
            <div class="col-auto" id="filterOutput">
                <label class="form-label small mb-1">Options</label>
                <div id="flowsFilterOther" data-bs-toggle="buttons">
                    <input type="checkbox" class="btn-check" name="ordertstart" value="true" id="flowsFilterOtherOrderTstart" data-bind:flows.order-by-tstart>
                    <label class="btn btn-outline-primary btn-sm" data-view="flows" for="flowsFilterOtherOrderTstart">Order by tstart</label>
                </div>
            </div>
        </div>
    </div>

    <!-- Commands Section -->
    <div class="col-12 border-top mt-3 pt-3">
        <nfsen-tooltip text="Execute nfdump query with current filters and display flow records" placement="top" no-icon>
            <button type="button"
                    class="btn btn-primary"
                    data-indicator:flows.indicator
                    data-class:loading="$flows.indicator"
                    data-on:click="@post('/api/flow-actions', {action: 'processFlows'})">
                <span data-show="!$flows.indicator">Process data</span>
                <span class="spinner-grow spinner-grow-sm" data-show="$flows.indicator" role="status" aria-hidden="true"></span>
                <span data-show="$flows.indicator">Processing...</span>
            </button>
        </nfsen-tooltip>
    </div>
</div>
