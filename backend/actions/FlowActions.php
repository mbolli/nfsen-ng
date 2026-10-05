<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\datasources\TotalsProvider;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\state\FlowRowStore;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\QueryEstimator;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use starfederation\datastar\enums\ElementPatchMode;

/**
 * The Flows page's actions (4.3.3): the run, its output in chunks, and the filtered totals. The
 * list's own requests are FlowWindowActions'.
 *
 * @phpstan-import-type RangeSummary from FlowsState
 *
 * @phpstan-type ListInputs array{tz: string, sortKey: string, sortDir: string, hidden: list<string>}
 * @phpstan-type Run array{limit?: int, fingerprint?: string, totalsFingerprint?: string, live?: bool, ranAt?: int,
 *                        rangeSummary?: ?RangeSummary, rangePending?: bool, list?: ListInputs}
 * @phpstan-type Inputs array{start: int, end: int, live: bool, profile: string, sources: list<string>, protocol: string,
 *                           filter: string, lower: string, upper: string, limit: int, aggregation: array<string, mixed>, orderByStart: bool}
 */
final class FlowActions {
    /** A live result older than this no longer covers the window (1.7). */
    public const int LIVE_MOVED_AFTER = 300;

    /** The largest row limit the Limit select offers. */
    public const int MAX_LIMIT = 10_000;

    /** The filtered totals' estimate, named as QueryKit names estimates. */
    public const string SUMMARY_ESTIMATE = '_est_flows_summary';

    /** Listings above this many rows run one at a time per worker. */
    public const int LARGE_ROWS = 1000;

    /** Heap a json row costs while nfdump's answer is parsed: about 650 B of text, read into a growing buffer... */
    public const int TEXT_BYTES_PER_ROW = 1536;

    /** ...and the decoded record (2.8 KiB measured with nfdump 1.7.8), which fills free pages first. */
    public const int RECORD_BYTES_PER_ROW = 3584;

    /** Left free for the rest of the worker while a large listing is parsed. */
    public const int MEMORY_MARGIN = 10 * 1024 * 1024;

    private const float LARGE_WAIT_SECONDS = 60.0;

    /** Lets QueryRunner's own sync send the table before the range totals are read. */
    private const int TOTALS_DELAY_US = 100_000;

    /** @var null|\WeakMap<Context, int> newest summary estimate request per tab */
    private static ?\WeakMap $tickets = null;

    private static bool $largeRun = false;

    public static function register(Context $c, PageStates $states, ?Via $app = null): void {
        $flows = $states->flows;

        $c->action(static function (Context $c) use ($flows): void {
            $flowCount = $c->getSignal('flows_count');
            $countLabel = $c->getSignal('flows_count_label');
            \assert($flowCount !== null && $countLabel !== null);
            $ipInfoUrl = $c->getAction('ip-info')?->url() ?? '';
            $time = microtime(true);
            $contextId = $c->getId();

            try {
                $inputs = self::inputs($c);
                $query = self::query($inputs, $contextId);
                $processor = $query->processor();
                $run = [
                    'fingerprint' => self::fingerprintOf($inputs),
                    'totalsFingerprint' => self::fingerprintOf($inputs, totalsOnly: true),
                    'live' => $inputs['live'],
                    'rangePending' => true,
                    'list' => self::listInputs($c),
                ];

                // Sized inside the coroutine: the walk over the window belongs off the response path.
                QueryRunner::run($c, 'flows', static fn (): int => $query->totalBytes(), 'Starting nfdump…', static function () use (
                    $c,
                    $query,
                    $processor,
                    $ipInfoUrl,
                    $flowCount,
                    $countLabel,
                    $time,
                    $contextId,
                    $flows,
                    $run
                ): void {
                    try {
                        $large = self::claimMemory($query->limit, handle: $contextId);

                        try {
                            $result = $query->run($processor);
                            self::storeResult($flows, $result, round(microtime(true) - $time, 3), $ipInfoUrl, $run + ['limit' => $query->limit, 'ranAt' => time()]);
                            unset($result);
                            $flows->settle();
                            if ($large) {
                                gc_mem_caches();
                            }
                        } finally {
                            if ($large) {
                                self::releaseMemory();
                            }
                        }
                        $flowCount->setValue($flows->count);
                        $countLabel->setValue($flows->countLabel());
                        self::readRangeTotals($c, $flows, $query);
                    } catch (\Throwable $e) {
                        // The cancel flag is read here because QueryRunner clears it after the work.
                        self::storeFailure($flows, $e, QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId)));
                        $flowCount->setValue(0);

                        // QueryRunner owns the status line ("Failed: ...").
                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                // Failure while building the command: nothing started, report synchronously.
                self::storeFailure($flows, $e, false);
                $flowCount->setValue(0);
                $c->sync();
            }
        }, 'flow-actions');

        $c->action(static function (Context $c) use ($flows): void {
            $time = microtime(true);
            $contextId = $c->getId();

            try {
                $inputs = self::inputs($c);
                $query = self::query($inputs, $contextId);
                $processor = $query->summaryProcessor();
                $fingerprint = self::fingerprintOf($inputs, totalsOnly: true);

                QueryRunner::run($c, 'flows-summary', static fn (): int => $query->totalBytes(), 'Starting nfdump…', static function () use (
                    $query,
                    $processor,
                    $time,
                    $contextId,
                    $flows,
                    $fingerprint
                ): void {
                    try {
                        self::storeSummary($flows, $query->runSummary($processor), round(microtime(true) - $time, 3), $fingerprint, time());
                    } catch (\Throwable $e) {
                        self::storeSummaryFailure($flows, $e, QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId)));

                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                self::storeSummaryFailure($flows, $e, false);
                $c->sync();
            }
        }, 'flows-summary-run');

        $c->action(static function (Context $c) use ($flows): void {
            self::sendChunk($c, $flows);
        }, 'flows-raw');

        $c->action(static function (Context $c) use ($app): void {
            try {
                self::estimateSummary($c, $app);
            } catch (\Throwable $e) {
                Debug::getInstance()->log('Could not estimate the filtered totals: ' . $e->getMessage(), LOG_ERR);
            }
        }, 'flows-summary-estimate');
    }

    /**
     * The next chunk of nfdump's output for the client that asks, as its own event, so no event
     * carries a whole large result. A dropped chunk makes the page say so.
     */
    public static function sendChunk(Context $c, FlowsState $flows): void {
        $result = $c->input('result');
        $index = $c->input('chunk');
        if (!\is_string($result) || $result !== $flows->resultId || !\is_string($index) || !ctype_digit($index)) {
            return;
        }
        $html = $flows->rawChunk((int) $index);
        if ($html === null) {
            if (!$flows->hasPayload()) {
                $flows->rawLost = true;
                $c->sync();
            }

            return;
        }

        $c->getPatchManager()->queuePatch([
            'type' => 'elements',
            'content' => $html,
            'selector' => "#flowsRawOutput-{$result}",
            'mode' => ElementPatchMode::Append,
        ]);
    }

    /**
     * Before a large listing: waits for the one large-run slot, then drops stored results until
     * its parse fits the heap (expectedPeak()). Throws when it cannot; returns whether it took the slot.
     */
    public static function claimMemory(int $rows, ?int $memoryLimit = null, string $handle = ''): bool {
        if ($rows <= self::LARGE_ROWS) {
            return false;
        }
        $deadline = microtime(true) + self::LARGE_WAIT_SECONDS;
        while (self::$largeRun) {
            if ($handle !== '' && QueryCancel::isRequested($handle)) {
                throw new \RuntimeException('Query cancelled.');
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Another large Flows query is still running. Run again in a moment.');
            }
            Coroutine::usleep(100_000);
        }

        $memoryLimit ??= self::memoryLimit();
        $ceiling = $memoryLimit - self::MEMORY_MARGIN;
        $fits = static function () use ($rows, $ceiling): bool {
            gc_mem_caches();

            return self::expectedPeak($rows) <= $ceiling;
        };
        if ($memoryLimit > 0 && !FlowsState::makeRoom($fits)) {
            $affordable = 0;
            for ($step = 8192; $step >= 100; $step = intdiv($step, 2)) {
                while ($affordable + $step < $rows && self::expectedPeak($affordable + $step) <= $ceiling) {
                    $affordable += $step;
                }
            }
            Debug::getInstance()->log(\sprintf(
                'Flows: %d rows would take the heap to %d MiB of %d MiB (%d MiB in use).',
                $rows,
                intdiv(self::expectedPeak($rows), 1 << 20),
                intdiv($memoryLimit, 1 << 20),
                intdiv(memory_get_usage(), 1 << 20),
            ), LOG_WARNING);

            throw new \RuntimeException(\sprintf(
                'The server has too little memory free for %s rows right now; about %s would fit. Lower the limit and run again.',
                number_format($rows),
                number_format($affordable - $affordable % 100),
            ));
        }
        self::$largeRun = true;

        return true;
    }

    /**
     * The heap a listing of $rows reaches: nfdump's text and its lines always take new memory,
     * the decoded records fill the free pages of the heap first.
     */
    public static function expectedPeak(int $rows): int {
        $heap = memory_get_usage(true);
        $free = max(0, $heap - memory_get_usage());

        return $heap + $rows * self::TEXT_BYTES_PER_ROW + max(0, $rows * self::RECORD_BYTES_PER_ROW - $free);
    }

    public static function releaseMemory(): void {
        self::$largeRun = false;
    }

    /**
     * The query the page describes, clamped to what the controls offer: every signal is client-writable.
     *
     * @return Inputs
     */
    public static function inputs(Context $c): array {
        $start = $c->getSignal('datestart')?->int() ?? 0;
        $limit = $c->getSignal('flows_limit')?->int() ?? Config::$settings->defaultFlowLimit;

        return [
            'start' => $start,
            'end' => max($start, $c->getSignal('dateend')?->int() ?? $start),
            'live' => $c->getSignal('range_live')?->bool() ?? false,
            'profile' => $c->getSignal('selected_profile')?->string() ?? '',
            'sources' => Helpers::resolveSources($c->getSignal('graph_sources')?->array() ?? []),
            'protocol' => RangeControls::protocol($c),
            'filter' => $c->getSignal('flows_filter')?->string() ?? '',
            'lower' => $c->getSignal('flows_lower_limit')?->string() ?? '',
            'upper' => $c->getSignal('flows_upper_limit')?->string() ?? '',
            'limit' => max(1, min(self::MAX_LIMIT, $limit)),
            'aggregation' => Helpers::aggregationFromSignals($c, 'flows_agg_'),
            'orderByStart' => $c->getSignal('flows_orderByTstart')?->bool() ?? false,
        ];
    }

    /**
     * What the Run copies from the browser for the list, none of it part of the query: the browser's
     * time zone, the sort ('<key> <asc|desc>') and the hidden columns, which prepare() checks.
     *
     * @return ListInputs
     */
    public static function listInputs(Context $c): array {
        $tz = $c->getSignal('flows_tz')?->string() ?? '';
        $sort = $c->getSignal('flows_sort')?->string() ?? '';
        $parts = explode(' ', $sort);
        $sorted = \count($parts) === 2 && preg_match(FlowsState::KEY_PATTERN, $parts[0]) === 1 && \in_array($parts[1], ['asc', 'desc'], true);

        return [
            'tz' => FlowRowStore::isZone($tz) ? $tz : '',
            'sortKey' => $sorted ? $parts[0] : '',
            'sortDir' => $sorted ? $parts[1] : '',
            'hidden' => array_values(array_filter($c->getSignal('flows_hidden')?->array() ?? [], \is_string(...))),
        ];
    }

    /** @param Inputs $inputs */
    public static function query(array $inputs, string $handle): FlowsQuery {
        return new FlowsQuery(
            // Not clamped: a flow listing is bounded by its record limit, not the range.
            window: TimeWindow::raw($inputs['start'], $inputs['end']),
            sources: $inputs['sources'],
            profile: $inputs['profile'],
            limit: $inputs['limit'],
            filter: $inputs['filter'],
            lowerLimit: $inputs['lower'],
            upperLimit: $inputs['upper'],
            aggregation: $inputs['aggregation'],
            orderByStart: $inputs['orderByStart'],
            handle: $handle,
            protocol: $inputs['protocol'],
        );
    }

    /**
     * The query as a person would describe it (1.7): a live window is its width, a fixed one is
     * rounded to the capture interval. The totals ignore the limit, ordering and aggregation.
     *
     * @param Inputs $inputs
     */
    public static function fingerprintOf(array $inputs, bool $totalsOnly = false): string {
        $window = $inputs['live']
            ? 'live:' . ($inputs['end'] - $inputs['start'])
            : ($inputs['start'] - $inputs['start'] % 300) . '-' . ($inputs['end'] - $inputs['end'] % 300);
        $parts = [
            $window,
            implode(',', $inputs['sources']),
            $inputs['profile'],
            $inputs['protocol'],
            trim($inputs['filter']),
            trim($inputs['lower']),
            trim($inputs['upper']),
        ];
        if (!$totalsOnly) {
            $parts[] = (string) $inputs['limit'];
            $parts[] = json_encode($inputs['aggregation']);
            $parts[] = $inputs['orderByStart'] ? 'tstart' : '';
        }

        return sha1(implode("\x1f", $parts));
    }

    /**
     * Stores a finished run: records as the list with the Run's sort and columns, else Table's empty
     * state; then the raw output in chunks, the returned rows' figures and the command's notice.
     *
     * @param Run $run
     */
    public static function storeResult(FlowsState $state, QueryResult $result, float $elapsed, string $ipInfoUrl, array $run = []): void {
        $limit = $run['limit'] ?? 0;
        $resultId = FlowsState::newResultId();
        $list = $run['list'] ?? ['tz' => '', 'sortKey' => '', 'sortDir' => '', 'hidden' => []];
        $state->releaseStored();
        $isList = FlowRowStore::store($resultId, $result->rows, ['linkIpAddresses' => true, 'ipInfoActionUrl' => $ipInfoUrl]);
        $html = $isList ? '' : Table::generate($result->rows, 'flowTable', [
            'linkIpAddresses' => true,
            'ipInfoActionUrl' => $ipInfoUrl,
            'caption' => 'Flows',
            'exportName' => self::exportName($result->window->start, $result->window->end),
            'emptyTitle' => 'No flows',
            'emptyMessage' => 'No flows match this query in the selected range.',
            'result' => $resultId,
        ]);

        $state->setResult($html, $result->count(), [
            'resultId' => $resultId,
            'mode' => $isList ? 'list' : 'html',
            'itemSize' => FlowRows::itemSize(isset(Config::$settings) && Config::$settings->compactTables),
            'browserTz' => $list['tz'],
            'limit' => $limit,
            'command' => $result->command,
            'notes' => $result->notes,
            'rawOutput' => \is_string($result->rawOutput) ? $result->rawOutput : '',
            'elapsed' => $elapsed,
            'returnedSummary' => FlowsQuery::returnedSummary($result->rows),
            'rangeSummary' => $run['rangeSummary'] ?? null,
            'rangePending' => $run['rangePending'] ?? false,
            'fingerprint' => $run['fingerprint'] ?? '',
            'totalsFingerprint' => $run['totalsFingerprint'] ?? '',
            'ranAt' => $run['ranAt'] ?? time(),
            'start' => $result->window->start,
            'end' => $result->window->end,
            'live' => $run['live'] ?? false,
        ]);
        if ($isList) {
            FlowWindowActions::prepare($state, $list['sortKey'], $list['sortDir'], $list['hidden']);
        }

        $state->notifyResult($result, $elapsed, 'Flows');
    }

    /** Stores a failed run. A cancelled run keeps kill-nfdump's notice instead of an error. */
    public static function storeFailure(FlowsState $state, \Throwable $e, bool $cancelled): void {
        if (!$cancelled) {
            Debug::getInstance()->log('Flow action error: ' . $e->getMessage(), LOG_ERR);
            $state->notifyFailure($e);
        }
        $state->clearResult();
    }

    public static function storeSummary(FlowsState $state, QueryResult $result, float $elapsed, string $fingerprint, int $now): void {
        $state->setFilteredSummary([
            'totals' => FlowsQuery::summaryFromOutput(\is_string($result->rawOutput) ? $result->rawOutput : ''),
            'command' => $result->command,
            'elapsed' => $elapsed,
            'fingerprint' => $fingerprint,
            'ranAt' => $now,
        ]);
    }

    /** A failed totals run adds its notice and leaves the flows result and its notices alone. */
    public static function storeSummaryFailure(FlowsState $state, \Throwable $e, bool $cancelled): void {
        if ($cancelled) {
            return;
        }
        Debug::getInstance()->log('Flows summary error: ' . $e->getMessage(), LOG_ERR);
        $state->notify('error', 'Filtered totals: ' . $e->getMessage(), $e instanceof NfdumpException ? $e->command : '');
    }

    /**
     * Unfiltered totals of the window from the stored series (D13); no capture file is read.
     *
     * @return RangeSummary
     */
    public static function rangeSummary(FlowsQuery $query, ?TotalsProvider $provider = null): array {
        $provider ??= isset(Config::$db) && Config::$db instanceof TotalsProvider ? Config::$db : null;
        if ($provider === null) {
            return ['available' => false, 'reason' => 'This datasource keeps no stored totals.', 'totals' => []];
        }

        try {
            $totals = $provider->fetchProtocolTotals($query->sources, $query->profile, $query->window->start, $query->window->end);

            return ['available' => true, 'reason' => '', 'totals' => $totals];
        } catch (\Throwable $e) {
            Debug::getInstance()->log('Flows range totals: ' . $e->getMessage(), LOG_WARNING);

            return ['available' => false, 'reason' => 'The stored totals could not be read.', 'totals' => []];
        }
    }

    /**
     * What the filtered totals read: every file of the Flows run, as a statistic has no row limit.
     *
     * @param list<string> $sources
     */
    public static function summaryEstimate(TimeWindow $window, array $sources, string $profile): Estimate {
        $flows = QueryEstimator::singlePass('flows', $window, $sources, $profile);
        $throughput = QueryEstimator::throughput('flows-summary');

        return new Estimate(
            files: $flows->files,
            bytes: $flows->bytes,
            runs: 1,
            seconds: $flows->files > 0 && $flows->bytes > 0 ? (int) ceil($flows->bytes / $throughput['bytesPerSecond']) : null,
            measured: $throughput['measured'],
            clamped: false,
            window: $flows->window,
        );
    }

    /** `flows-<from>-<to>` in the server's time, for exports and the raw output download. */
    public static function exportName(int $start, int $end): string {
        return 'flows-' . date('YmdHi', $start) . '-' . date('YmdHi', $end);
    }

    /**
     * `_est_flows_summary`: pending first, then the answer of the newest request, as the query
     * kit answers `estimate-query`.
     */
    private static function estimateSummary(Context $c, ?Via $app): void {
        $signal = $c->getSignal(self::SUMMARY_ESTIMATE);
        if ($signal === null) {
            return;
        }
        self::$tickets ??= new \WeakMap();
        $ticket = (self::$tickets[$c] ?? 0) + 1;
        self::$tickets[$c] = $ticket;
        $current = \is_array($signal->getValue()) ? $signal->getValue() : [];

        $plan = QueryKitActions::plan($c, 'flows');
        if ($plan === null) {
            $signal->setValue([...QueryKit::ESTIMATE_DEFAULT, 'pending' => false]);
            self::push($c, $app);

            return;
        }

        $signal->setValue([...QueryKit::ESTIMATE_DEFAULT, ...$current, 'pending' => true]);
        self::push($c, $app);

        Coroutine::create(static function () use ($c, $app, $signal, $plan, $ticket, $current): void {
            try {
                $estimate = self::summaryEstimate($plan['window'], $plan['sources'], $plan['profile']);
                if ((self::$tickets[$c] ?? 0) === $ticket) {
                    $signal->setValue(QueryKitActions::estimatePayload($estimate));
                    self::push($c, $app);
                }
            } catch (\Throwable $e) {
                Debug::getInstance()->log('Could not estimate the filtered totals: ' . $e->getMessage(), LOG_ERR);
                if ((self::$tickets[$c] ?? 0) === $ticket) {
                    $signal->setValue([...QueryKit::ESTIMATE_DEFAULT, ...$current, 'pending' => false]);
                    self::push($c, $app);
                }
            }
        });
    }

    /**
     * The range totals after QueryRunner's sync has sent the table: a stalled VictoriaMetrics
     * answers slowly (A3), and the rows should not wait for it.
     */
    private static function readRangeTotals(Context $c, FlowsState $flows, FlowsQuery $query): void {
        $resultId = $flows->resultId;
        Coroutine::create(static function () use ($c, $flows, $query, $resultId): void {
            try {
                Coroutine::usleep(self::TOTALS_DELAY_US);
                if ($flows->setRangeSummary($resultId, self::rangeSummary($query))) {
                    $c->sync();
                }
            } catch (\Throwable $e) {
                Debug::getInstance()->log('Flows range totals: ' . $e->getMessage(), LOG_WARNING);
            }
        });
    }

    /** The worker's memory_limit in bytes, 0 when there is none. */
    private static function memoryLimit(): int {
        $value = trim((string) \ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number << 30,
            'm' => $number << 20,
            'k' => $number << 10,
            default => $number,
        };
    }

    /** Before the tab's SSE stream is up a patch would be dropped; the connect sync carries it. */
    private static function push(Context $c, ?Via $app): void {
        if ($app !== null && !$c->isConnected()) {
            return;
        }
        $c->syncSignals();
    }
}
