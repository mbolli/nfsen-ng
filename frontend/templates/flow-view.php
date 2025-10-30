<?php
/**
 * Flows View Component
 * Displays network flow data with filtering and search.
 */
?>

<!-- Flow Table with SSE Updates -->
<div class="row">
    <div class="col-12">
        <div class="card" data-class:loading="$flows.indicator">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Network Flows</h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-info" data-text="$flows.count || '0'"></span>
                        <span class="text-muted small">flows</span>
                    </div>
                </div>

                <div id="flowMessage"></div>
                <div id="flowTable"></div>
            </div>
        </div>
    </div>
</div>
