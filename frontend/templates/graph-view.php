<?php
/**
 * Graph View Component
 * Displays traffic graphs with visualization options.
 */
?>

<!-- Graph Container with SSE Updates -->
    <div class="col-12">
        <div class="d-flex justify-content-end mb-3 mt-2">
            <div>
                <span class="badge bg-success"
                        data-show="$graph._isLive"
                        title="Graph is showing real-time data">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" class="bi bi-circle-fill me-1" viewBox="0 0 16 16">
                        <circle cx="8" cy="8" r="8"/>
                    </svg>
                    LIVE
                    <span class="fw-light" data-show="$graph._lastUpdate != ''">last update: <span data-text="$graph._lastUpdate"></span></span>
                </span>
                <span class="badge bg-secondary"
                        data-show="!$graph._isLive"
                        title="Graph is showing historical data">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" class="bi bi-clock-history me-1" viewBox="0 0 16 16">
                        <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022zm2.004.45a7 7 0 0 0-.985-.299l.219-.976q.576.129 1.126.342zm1.37.71a7 7 0 0 0-.439-.27l.493-.87a8 8 0 0 1 .979.654l-.615.789a7 7 0 0 0-.418-.302zm1.834 1.79a7 7 0 0 0-.653-.796l.724-.69q.406.443.747.933zm.744 1.352a7 7 0 0 0-.214-.468l.893-.45a8 8 0 0 1 .45 1.088l-.95.313a7 7 0 0 0-.179-.483m.53 2.507a7 7 0 0 0-.1-1.025l.985-.17q.1.58.116 1.17zm-.131 1.538q.05-.254.081-.51l.993.123a8 8 0 0 1-.23 1.155l-.964-.267q.069-.247.12-.501m-.952 2.379q.276-.436.486-.908l.914.405q-.24.54-.555 1.038zm-.964 1.205q.183-.183.35-.378l.758.653a8 8 0 0 1-.401.432z"/>
                        <path d="M8 1a7 7 0 1 0 4.95 11.95l.707.707A8.001 8.001 0 1 1 8 0z"/>
                        <path d="M7.5 3a.5.5 0 0 1 .5.5v5.21l3.248 1.856a.5.5 0 0 1-.496.868l-3.5-2A.5.5 0 0 1 7 9V3.5a.5.5 0 0 1 .5-.5"/>
                    </svg>
                    HISTORICAL
                </span>
                <span class="badge bg-info text-dark ms-1"
                        data-show="$graph._actualResolution > 0"
                        title="Number of data points in the graph">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" class="bi bi-bar-chart-line me-1" viewBox="0 0 16 16">
                        <path d="M11 2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12h.5a.5.5 0 0 1 0 1H.5a.5.5 0 0 1 0-1H1v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h1V7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7h1zm1 12h2V2h-2zm-3 0V7H7v7zm-5 0v-3H2v3z"/>
                    </svg>
                    <span data-text="$graph._actualResolution"></span> points
                </span>
            </div>
        </div>

        <!-- Graph -->
        <div id="graph"></div>

        <!-- Graph Options -->
        <div class="row mt-3" id="graphOptions">
            <div class="col-md-2">
                <p class="h6">Resolution</p>
                <div class="mb-2">
                    <label for="resolutionSlider" class="form-label">
                        Data Points: <strong data-text="$graph.resolution"></strong>
                    </label>
                    <input type="range"
                            class="form-range"
                            id="resolutionSlider"
                            min="50"
                            max="2000"
                            step="50"
                            data-bind:graph.resolution
                            title="Number of data points to display (higher = more detail)"
                            data-on:input="el.dispatchEvent(new Event('change', { bubbles: true }));"
                            >
                    <div class="d-flex justify-content-between small text-muted">
                        <span>50</span>
                        <span>2000</span>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <p class="h6">Graph Scale</p>
                <div class="btn-group" role="group" id="graph_linlog">
                    <input type="radio"
                            class="btn-check"
                            name="scale"
                            value="linear"
                            checked
                            id="graph_linlog_linear"
                            data-on:change__stop="$graph._widget.updateOptions({logscale: false})">
                    <label for="graph_linlog_linear" class="btn btn-outline-primary btn-sm">Linear</label>

                    <input type="radio"
                            class="btn-check"
                            name="scale"
                            value="logarithmic"
                            id="graph_linlog_log"
                            data-on:change__stop="$graph._widget.updateOptions({logscale: true})">
                    <label for="graph_linlog_log" class="btn btn-outline-primary btn-sm">Logarithmic</label>
                </div>
            </div>

            <div class="col-md-3">
                <p class="h6">Series Display</p>
                <div class="btn-group" role="group" data-on:change__stop="const stacked = evt.target.value === 'stacked'; $graph._widget.updateOptions({stackedGraph: stacked, fillGraph: stacked})">
                    <input type="radio"
                            class="btn-check"
                            name="type"
                            value="stacked"
                            id="graph_linestacked_stacked">
                    <label class="btn btn-outline-primary btn-sm" for="graph_linestacked_stacked">Stacked</label>

                    <input type="radio"
                            class="btn-check"
                            name="type"
                            value="line"
                            checked
                            id="graph_linestacked_line">
                    <label class="btn btn-outline-primary btn-sm" for="graph_linestacked_line">Line</label>
                </div><br>

                <div class="btn-group mt-2" role="group" id="graph_lineplot">
                    <input type="radio"
                            class="btn-check"
                            name="lineplot"
                            value="step"
                            checked
                            id="graph_lineplot_step"
                            data-on:change__stop="$graph._widget.updateOptions({stepPlot: true})">
                    <label class="btn btn-outline-primary btn-sm" for="graph_lineplot_step">Step plot</label>

                    <input type="radio"
                            class="btn-check"
                            name="lineplot"
                            value="curve"
                            id="graph_lineplot_curve"
                            data-on:change__stop="$graph._widget.updateOptions({stepPlot: false})">
                    <label class="btn btn-outline-primary btn-sm" for="graph_lineplot_curve">Curve plot</label>
                </div>
            </div>

            <div class="col-md-2">
                <p class="h6">Series Visibility</p>
                <div id="series">
                    <!-- Dynamically populated when chart loads with series checkboxes -->
                </div>
            </div>

            <div class="col-md-3">
                <p class="h6">Legend</p>
                <div id="legend">
                    <!-- Dygraph legend appears here -->
                </div>
            </div>
        </div>
    </div>
</div>
