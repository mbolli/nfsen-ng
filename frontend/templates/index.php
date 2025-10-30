<?php
/**
 * nfsen-ng main entry point - Single-page hypermedia application
 * All views are rendered server-side and toggled with Datastar signals.
 */

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

use mbolli\nfsen_ng\common\Config;

// Initialize configuration
Config::initialize();

// Get import years setting (default: 3)
$importYears = (int) (getenv('NFSEN_IMPORT_YEARS') ?: 3);

// Get available sources and ports from config
$sources = Config::$cfg['general']['sources'] ?? [];
$ports = Config::$cfg['general']['ports'] ?? [];
$version = Config::VERSION;

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>nfsen-ng</title>

    <!-- CSS Dependencies -->
    <link rel="stylesheet" type="text/css" href="frontend/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="frontend/css/dygraph.css" />
    <link rel="stylesheet" type="text/css" href="frontend/css/ion.rangeSlider.css" />
    <link rel="stylesheet" type="text/css" href="frontend/css/nfsen-ng.css" />
</head>

<body class="p-2" data-signals='{"_currentView": "graphs", "datestart": <?php echo time() - 86400; ?>, "dateend": <?php echo time(); ?>}'>
    <header>
        <a class="position-absolute link-secondary" style="text-decoration: none;" href="https://github.com/mbolli/nfsen-ng" target="_blank">
            nfsen-ng <span id="version"><?php echo htmlspecialchars($version); ?></span>
        </a>

        <?php include __DIR__ . '/nav.php'; ?>
    </header>

    <main class="container-fluid tab-content">
        <!-- Error Alert -->
        <div class="alert alert-danger" data-effect="el.style.display = ($_error != null && $_error != '') ? 'block' : 'none'" role="alert" data-text="$_error" style="display: none;"></div>

        <div class="filter row align-items-start bg-light border-bottom py-3 mb-3" style="flex-direction: row">

            <!-- Date Range Selector -->
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <!-- Date Range Slider -->
                        <div class="mb-3">
                            <nfsen-daterange
                                id="dateRangeSlider"
                                data-min="<?php echo strtotime("now - {$importYears} years"); ?>000"
                                data-max="<?php echo time(); ?>000"
                                data-from="<?php echo strtotime('now - 1 day'); ?>000"
                                data-to="<?php echo time(); ?>000"
                                data-ref="_daterangeWidget"
                                data-on-interval__duration.60s="el.dataset.min = new Date().getTime() - <?php echo $importYears; ?>*365*24*60*60*1000; el.dataset.max = new Date().getTime();"
                                data-on:range-change="$datestart = Math.floor(evt.detail.from / 1000); $dateend = Math.floor(evt.detail.to / 1000); el.dispatchEvent(new Event('change', { bubbles: true }));">
                            </nfsen-daterange>
                        </div>

                        <!-- Quick Select Buttons -->
                        <div class="d-flex justify-content-end gap-2 flex-wrap mb-4 date-range-controls">
                            <div class="btn-group" id="date_slot" role="group">
                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').setQuickRange(3600000)">
                                    1 hour
                                </button>

                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').setQuickRange(86400000)">
                                    24 hours
                                </button>

                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').setQuickRange(604800000)">
                                    Week
                                </button>

                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').setQuickRange(2592000000)">
                                    Month
                                </button>

                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').setQuickRange(31536000000)">
                                    Year
                                </button>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="btn-group" id="date_slot_nav" role="group">
                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').navigate('prev')"
                                        title="Previous period">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left" viewBox="0 0 16 16">
                                        <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8"/>
                                    </svg>
                                </button>
                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').navigate('next')"
                                        title="Next period">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-right" viewBox="0 0 16 16">
                                        <path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8"/>
                                    </svg>
                                </button>
                                <button type="button"
                                        class="btn btn-outline-primary"
                                        data-on:click="document.getElementById('dateRangeSlider').goToEnd()"
                                        title="Jump to now (most recent data)">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-arrow-bar-right" viewBox="0 0 16 16" width="16"><path d="M3 8a.5.5 0 0 1 .5-.5h5.793L7.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 1 1-.708-.708L9.293 8.5H3.5A.5.5 0 0 1 3 8" style="transform-box:fill-box" transform-origin="50% 50%"/><path d="M13.501 1a.5.5 0 0 0-.5.5v13a.5.5 0 0 0 1 0v-13a.5.5 0 0 0-.5-.5" style="transform-box:fill-box" transform="rotate(180 0 0)" transform-origin="50% 50%"/></svg>
                                </button>
                            </div>

                            <!-- Sync Button - Copies graph zoom/pan range to date slider -->
                            <div class="btn-group" id="date_syncing" role="group">
                                <nfsen-tooltip text="Sync date range slider with current graph zoom/pan level" placement="bottom" no-icon>
                                    <button type="button"
                                            class="btn btn-outline-primary sync-date"
                                            disabled
                                            data-on:click="const range = $_graphWidget.getCurrentRange(); $_daterangeWidget.updateRange(range.from, range.to); el.disabled = true;"
                                            data-show="$_currentView == 'graphs'">
                                        Copy from graph
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-repeat" viewBox="0 0 16 16">
                                            <path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41m-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9"/>
                                            <path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5 5 0 0 0 8 3M3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9z"/>
                                        </svg>
                                    </button>
                                </nfsen-tooltip>
                            </div>
                        </div>


                        <!-- View-specific filters -->
                        <div data-show="$_currentView == 'graphs'" class="col-12">
                            <?php include __DIR__ . '/graph-filters.php'; ?>
                        </div>

                        <div data-show="$_currentView == 'flows'" class="col-12" style="display: none;">
                            <?php include __DIR__ . '/flow-filters.php'; ?>
                        </div>

                        <div data-show="$_currentView == 'statistics'" class="col-12" style="display: none;">
                            <?php include __DIR__ . '/stats-filters.php'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Graphs View -->
        <div data-show="$_currentView == 'graphs'" class="col-12">
            <?php include __DIR__ . '/graph-view.php'; ?>
        </div>

        <!-- Flows View -->
        <div data-show="$_currentView == 'flows'" class="col-12" style="display: none;">
            <?php include __DIR__ . '/flow-view.php'; ?>
        </div>

        <!-- Statistics View -->
        <div data-show="$_currentView == 'statistics'" class="col-12" style="display: none;">
            <?php include __DIR__ . '/stats-view.php'; ?>
        </div>
    </main>

    <!-- Server Stats Footer Dashboard -->
    <div data-init="@get('/api/server-stats')">
        <div id="server-stats">
            <!-- Stats will be streamed here -->
            <div class="mt-2 p-2 bg-light border rounded text-center text-muted">
                <small>Loading server stats...</small>
            </div>
        </div>
    </div>

    <footer class="mt-2 text-center text-muted small">
        <p>nfsen-ng <?php echo htmlspecialchars($version); ?> &mdash; NetFlow Sensor Next Generation</p>
    </footer>

    <!-- JS Dependencies (legacy, will be removed during jQuery cleanup) -->
    <script src="frontend/js/jquery.min.js"></script>
    <script src="frontend/js/popper.min.js"></script>
    <script src="frontend/js/bootstrap.min.js"></script>
    <script src="frontend/js/ion.rangeSlider.min.js"></script>
    <script src="frontend/js/dygraph.min.js"></script>


    <!-- Datastar - Hypermedia state management -->
     <script type="module">
        import * as datastar from "./frontend/js/datastar.js";

        // Custom attribute binding to sync element properties with signals
        datastar.attribute({
            name: 'prop',
            requirement: {
                value: 'must',
            },
            returnsValue: true,
            apply({ el, key, rx }) {
                const update = key
                    ? () => { // Single property
                        observer.disconnect()
                        const val = rx()
                        el[key] = val
                        observer.observe(el, { attributeFilter: [key] })
                    }
                    : () => { // Multiple properties
                        observer.disconnect()
                        const obj = rx()
                        const attributeFilter = Object.keys(obj)
                        for (const key of attributeFilter) {
                            el[key] = obj[key]
                        }
                        observer.observe(el, { attributeFilter })
                    }
                const observer = new MutationObserver(update)
                const cleanup = datastar.effect(update)

                return () => {
                    observer.disconnect()
                    cleanup()
                }
            }
        });

        /**
         * Check if graph update should proceed based on event source
         * @param {Event} evt - The change event
         * @param {HTMLElement} el - The graph filters container element
         * @returns {boolean} - True if graph should update, false otherwise
         */
        window.mayUpdateGraph = (function() {
            // Cache DOM nodes for performance (since this may be called multiple times per second)
            let dateRangeNode = null;
            let dateRangeControlsNode = null;

            return function(evt, el) {
                // Get the event target (the element that triggered the change)
                const target = evt.target;

                // Check if event came from within the graph filters container (el)
                if (el && el.contains(target)) {
                    return true;
                }

                // Check if event came from nfsen-daterange component (cached)
                if (!dateRangeNode) {
                    dateRangeNode = document.querySelector('nfsen-daterange');
                }
                if (dateRangeNode && dateRangeNode.contains(target)) {
                    return true;
                }

                // Check if event came from date-range-controls container (cached)
                if (!dateRangeControlsNode) {
                    dateRangeControlsNode = document.querySelector('.date-range-controls');
                }
                if (dateRangeControlsNode && dateRangeControlsNode.contains(target)) {
                    return true;
                }

                // If event came from somewhere else, don't update
                return false;
            };
        })();
    </script>
    <script type="module" src="frontend/js/datastar.js"></script>

    <!-- Web Components -->
    <script type="module" src="frontend/js/components/nfsen-chart.js"></script>
    <script type="module" src="frontend/js/components/nfsen-table.js"></script>
    <script type="module" src="frontend/js/components/nfsen-daterange.js"></script>
    <script type="module" src="frontend/js/components/dark-mode-toggle.js"></script>
    <script type="module" src="frontend/js/components/nfsen-toast.js"></script>
    <script type="module" src="frontend/js/components/nfsen-ip-info-modal.js"></script>
    <script type="module" src="frontend/js/components/nfsen-filter-manager.js"></script>
    <script type="module" src="frontend/js/components/nfsen-tooltip.js"></script>
</body>
</html>
