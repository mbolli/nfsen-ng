<?php
/**
 * Graph View Filters Component
 * Filter controls for the graph view.
 */

use mbolli\nfsen_ng\common\Config;

// Get sources and ports from config
$sources = Config::$cfg['general']['sources'] ?? [];
$ports = Config::$cfg['general']['ports'] ?? [];
?>

<div class="row"
    data-signals:graph.display="'sources'"
    data-signals:graph.sources='<?php echo json_encode($sources); ?>'
    data-signals:graph.ports='<?php echo json_encode($ports); ?>'
    data-signals:graph.protocols='<?php echo json_encode(['any']); ?>'
    data-signals:graph.datatype="'traffic'"
    data-signals:graph.traffic-unit="'bits'"
    data-signals:graph.resolution="500"
    data-signals:graph._is-live="false"
    data-signals:graph._actual-resolution="0"
    data-computed:graph._config="{ sources: $graph.sources, protocols: $graph.protocols, ports: $graph.ports, display: $graph.display, type: $graph.datatype, trafficUnit: $graph.trafficUnit }"
    data-indicator:graph._connecting
    data-on-interval__duration.15s.leading="!$graph._connecting && $graph._isLive && @get('/api/graphs', { openWhenHidden: true })"
    data-init="@get('/api/graphs', { openWhenHidden: true })"
    data-on:change__window__throttle.250ms="window.mayUpdateGraph(evt, el) && @get('/api/graphs', { openWhenHidden: true })">

    <!-- Display Type -->
    <div class="col-md-2">
        <p class="h6">Display <nfsen-tooltip text="Choose what to visualize: individual sources, protocol breakdown, or port statistics." placement="top"></nfsen-tooltip></p>
        <select id="filterDisplaySelect"
                class="form-control form-select"
                data-bind:graph.display>
            <option value="sources">Sources</option>
            <option value="protocols">Protocols</option>
            <option value="ports">Ports</option>
        </select>
    </div>

    <div class="col-md-2" id="filterSources">
        <p class="h6">Sources <nfsen-tooltip text="Select NetFlow sources to include in the graph. Choose multiple for stacked display or 'Any' to aggregate all." placement="top"></nfsen-tooltip></p>
        <select id="filterSourcesSelect"
                class="form-control form-select"
                data-bind:graph.sources
                data-attr:multiple="$graph.display === 'sources'">
            <option value="any" data-attr:disabled="$graph.display === 'sources' || $graph.display === 'protocols'">Any</option>
            <?php foreach ($sources as $source) { ?>
                <option value="<?php echo htmlspecialchars($source); ?>" data-attr:selected="$graph.display === 'sources'"><?php echo htmlspecialchars($source); ?></option>
            <?php } ?>
        </select>
    </div>

    <!-- Ports - shown only when display=ports -->
    <div class="col-md-2"
        id="filterPorts"
        data-show="$graph.display === 'ports'">
        <p class="h6">Ports <nfsen-tooltip text="Select ports to track. Each port shows as a separate series in the graph." placement="top"></nfsen-tooltip></p>
        <select id="filterPortsSelect"
                multiple
                class="form-control form-select"
                data-bind:graph.ports>
            <?php foreach ($ports as $port) { ?>
                <option value="<?php echo htmlspecialchars($port); ?>" selected><?php echo htmlspecialchars($port); ?></option>
            <?php } ?>
        </select>
    </div>

    <!-- Protocols - shown for all modes -->
    <div class="col-md-4" id="filterProtocols">
        <p class="h6">Protocols <nfsen-tooltip text="Filter by protocol. 'Any' shows all traffic. In Protocols display mode, each protocol is shown as a separate series." placement="top"></nfsen-tooltip></p>
        <div class="btn-group" role="group" id="filterProtocolButtons">
            <input type="radio"
                class="btn-check"
                name="protocol"
                id="filterProtocolAny"
                value="any"
                checked
                data-attr:type="$graph.display === 'protocols' ? 'checkbox' : 'radio'"
                data-on:change="el.type === 'radio' ? el.checked && ($graph.protocols = [el.value]) : (el.checked ? ($graph.protocols = ['tcp', 'udp', 'icmp', 'other']) : $graph.protocols = ['tcp'])">
            <label class="btn btn-outline-primary btn-sm" for="filterProtocolAny">Any</label>
            <?php
            $protocols = [
                'tcp' => 'TCP',
                'udp' => 'UDP',
                'icmp' => 'ICMP',
                'other' => 'Others',
            ];
foreach ($protocols as $value => $label) {
    $inputId = 'filterProtocol' . ucfirst($value);
    ?>
                <input type="radio"
                    class="btn-check"
                    name="protocol"
                    id="<?php echo $inputId; ?>"
                    value="<?php echo $value; ?>"
                    data-attr:type="$graph.display === 'protocols' ? 'checkbox' : 'radio'"
                    data-prop:checked="$graph.protocols.includes('<?php echo $value; ?>')"
                    data-on:change="el.type === 'radio' ? el.checked && ($graph.protocols = [el.value]) : (el.checked ? $graph.protocols.push(el.value) && $graph.protocols.filter(p => p !== 'any') : $graph.protocols = $graph.protocols.filter(p => p !== el.value))"
                >
                <label class="btn btn-outline-primary btn-sm" for="<?php echo $inputId; ?>"><?php echo $label; ?></label>
            <?php } ?>
        </div>
    </div>

    <!-- Data Type -->
    <div class="col-md-2" id="filterTypes">
        <p class="h6">Data Type <nfsen-tooltip text="Choose metric to graph: Traffic (bytes/bits per second), Packets (packets per second), or Flows (flow records per second)." placement="top"></nfsen-tooltip></p>
        <div class="btn-group" role="group">
            <input type="radio"
                class="btn-check"
                name="datatype"
                value="traffic"
                checked
                id="dataTypeTraffic"
                data-bind:graph.datatype>
            <label class="btn btn-outline-primary btn-sm" for="dataTypeTraffic">Traffic</label>

            <input type="radio"
                class="btn-check"
                name="datatype"
                value="packets"
                id="dataTypePackets"
                data-bind:graph.datatype>
            <label class="btn btn-outline-primary btn-sm" for="dataTypePackets">Packets</label>

            <input type="radio"
                class="btn-check"
                name="datatype"
                value="flows"
                id="dataTypeFlows"
                data-bind:graph.datatype>
            <label class="btn btn-outline-primary btn-sm" for="dataTypeFlows">Flows</label>
        </div>
    </div>

    <!-- Traffic Unit - shown only when datatype=traffic -->
    <div class="col-md-1"
        id="trafficUnit"
        data-show="$graph.datatype === 'traffic'">
        <p class="h6">Unit <nfsen-tooltip text="Display traffic in bits/s (base-2: KiB, MiB) or bytes/s (base-10: KB, MB)." placement="top"></nfsen-tooltip></p>
        <div class="btn-group" role="group">
            <input type="radio"
                class="btn-check"
                name="trafficUnit"
                value="bits"
                checked
                id="trafficUnitBits"
                data-bind:graph.traffic-unit
                data-on:change="$graph._widget.updateOptions({ylabel: 'Bits/s', labelsKMG2: true})">
            <label class="btn btn-outline-primary btn-sm" for="trafficUnitBits">Bits</label>

            <input type="radio"
                class="btn-check"
                name="trafficUnit"
                value="bytes"
                id="trafficUnitBytes"
                data-bind:graph.traffic-unit
                data-on:change="$graph._widget.updateOptions({ylabel: 'Bytes/s', labelsKMG2: false})">
            <label class="btn btn-outline-primary btn-sm" for="trafficUnitBytes">Bytes</label>
        </div>
    </div>
</div>
