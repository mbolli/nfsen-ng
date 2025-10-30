<?php
/**
 * Statistics View Component
 * Displays aggregated statistics and reports.
 */
?>

<!-- Stats tables and summaries -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card" data-class:loading="$stats.indicator">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Output</h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-info" data-text="$stats.count || '0'"></span>
                        <span class="text-muted small">flows</span>
                    </div>
                </div>

                <div id="statsMessage"></div>
                <div id="statsTable"></div>
            </div>
        </div>
    </div>
</div>
