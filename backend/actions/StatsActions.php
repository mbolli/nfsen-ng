<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\state\TalkersState;
use mbolli\nfsen_ng\pages\TalkersPage;
use mbolli\nfsen_ng\processor\MultiStatCsvParser;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatisticCatalog;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;

/**
 * Top Talkers actions (4.2.3): stats-actions runs the chosen statistic, talkers-panel one of
 * the side panels, talkers-select shows the stored run of a statistic chosen on the client.
 *
 * @phpstan-import-type StatsInputs from TalkersState
 * @phpstan-import-type PanelInputs from TalkersState
 * @phpstan-import-type PanelBar from TalkersState
 * @phpstan-import-type StatRow from MultiStatCsvParser
 * @phpstan-import-type Notification from \mbolli\nfsen_ng\pages\state\PageState
 *
 * @phpstan-type StatsParams array{element: string, order: string, count: int, filter: string, lower: string, upper: string, aggregation: array<string, bool|string>, sources: list<string>, profile: string, protocol: string, start: int, end: int, live: bool}
 */
final class StatsActions {
    /** @var list<int> the Top records choices */
    public const array COUNTS = [10, 20, 50, 100, 200, 500];

    /** @var array<string, string> side panel => its nfdump element */
    public const array PANEL_ELEMENTS = ['proto' => 'proto', 'as' => 'as'];

    public const int PANEL_ROWS = 10;

    /** @var array<int, int> protocol number => the picker graph's series slot (2.3) */
    public const array PROTOCOL_SLOTS = [6 => 1, 17 => 2, 1 => 3, 58 => 3];

    /** Windows are compared at the granularity of the capture files. */
    public const int WINDOW_STEP = 300;

    public static function register(Context $c, PageStates $states): void {
        $talkers = $states->talkers;

        $c->action(static function (Context $c) use ($talkers): void {
            $time = microtime(true);
            $contextId = $c->getId();
            $ipInfoUrl = $c->getAction('ip-info')?->url() ?? '';
            $rows = $c->getSignal('_stats_rows');
            $params = self::params($c);
            if ($c->getSignal('query_running')?->bool() ?? false) {
                return;
            }
            $talkers->clearNotifications();

            try {
                $query = self::query($params, $contextId);
                $reason = TalkersPage::unsupported(time())[$query->for] ?? null;
                if ($reason !== null) {
                    throw new \InvalidArgumentException(StatisticCatalog::label($query->for) . ' is not supported by this nfdump: ' . $reason);
                }

                $clampNotice = $query->window->clamped ? $query->window->clampNotice() : '';
                // Sized inside the coroutine: the walk stat()s every file of the window.
                $totalBytes = static fn (): int => $query->totalBytes();
                $processor = $query->processor();

                QueryRunner::run($c, 'stats', $totalBytes, 'Starting nfdump…', static function (?\Closure $onSplit = null) use (
                    $query,
                    $processor,
                    $ipInfoUrl,
                    $time,
                    $contextId,
                    $clampNotice,
                    $talkers,
                    $params,
                    $rows
                ): void {
                    try {
                        $result = $query->runPartitioned('stats', $processor, $onSplit, static fn (): bool => QueryCancel::isRequested($contextId));
                        self::storeResult($talkers, $result, round(microtime(true) - $time, 3), $ipInfoUrl, $clampNotice, $params);
                        $rows?->setValue($result->count());
                    } catch (\Throwable $e) {
                        // Read here: QueryRunner clears the cancel flag after the work.
                        self::storeFailure($talkers, $e, QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId)), $params['element']);

                        // QueryRunner owns the status line ("Failed: ...").
                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                self::storeFailure($talkers, $e, false, $params['element']);
                $c->sync();
            }
        }, 'stats-actions');

        $c->action(static function (Context $c) use ($talkers): void {
            $panel = $c->input('panel');
            if (!\is_string($panel) || !isset(self::PANEL_ELEMENTS[$panel])) {
                return;
            }
            $time = microtime(true);
            $contextId = $c->getId();
            $params = self::params($c);
            // They belong to the results card, which a Kill of this panel run must not touch.
            $notices = $talkers->notifications;

            try {
                $query = self::panelQuery($params, $panel, $contextId);
                $totalBytes = static fn (): int => $query->totalBytes();
                $processor = $query->processor();

                QueryRunner::run($c, 'talkers-panel', $totalBytes, 'Starting nfdump…', static function (?\Closure $onSplit = null) use (
                    $query,
                    $processor,
                    $time,
                    $contextId,
                    $talkers,
                    $panel,
                    $params,
                    $notices
                ): void {
                    try {
                        $result = $query->runPartitioned('talkers-panel', $processor, $onSplit, static fn (): bool => QueryCancel::isRequested($contextId));
                        self::storePanel($talkers, $panel, $query->statRows($result), $result, round(microtime(true) - $time, 3), $params);
                    } catch (\Throwable $e) {
                        self::storePanelFailure($talkers, $panel, $e, QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId)), $params, $notices);

                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                self::storePanelFailure($talkers, $panel, $e, false, $params);
                $c->sync();
            }
        }, 'talkers-panel');

        // Re-renders only; the statistic itself arrives with the posted signals.
        $c->action(static function (Context $c): void {
            try {
                $c->sync();
            } catch (\Throwable $e) {
                Debug::getInstance()->log('talkers-select failed: ' . $e->getMessage(), LOG_ERR);
            }
        }, 'talkers-select');
    }

    /**
     * The page's inputs as a run would use them, normalised the same way for the run and for
     * the render that compares against it.
     *
     * @return StatsParams
     */
    public static function params(Context $c): array {
        $count = $c->getSignal('stats_count')?->int() ?? self::COUNTS[0];
        $aggregation = [];
        foreach (Helpers::aggregationFromSignals($c, 'stats_agg_') as $key => $value) {
            $aggregation[$key] = \is_bool($value) ? $value : trim(\is_scalar($value) ? (string) $value : '');
        }
        $sources = Helpers::resolveSources($c->getSignal('graph_sources')?->array() ?? []);
        sort($sources);

        return [
            'element' => trim($c->getSignal('stats_for')?->string() ?? ''),
            'order' => $c->getSignal('stats_orderBy')?->string() ?? 'bytes',
            'count' => \in_array($count, self::COUNTS, true) ? $count : self::COUNTS[0],
            'filter' => trim($c->getSignal('stats_filter')?->string() ?? ''),
            'lower' => trim($c->getSignal('stats_lower_limit')?->string() ?? ''),
            'upper' => trim($c->getSignal('stats_upper_limit')?->string() ?? ''),
            'aggregation' => $aggregation,
            'sources' => $sources,
            'profile' => $c->getSignal('selected_profile')?->string() ?? '',
            'protocol' => RangeControls::protocol($c),
            'start' => $c->getSignal('datestart')?->int() ?? 0,
            'end' => $c->getSignal('dateend')?->int() ?? 0,
            'live' => $c->getSignal('range_live')?->bool() ?? false,
        ];
    }

    /**
     * @param StatsParams $p
     *
     * @throws \InvalidArgumentException for a statistic, order or protocol outside the catalog
     */
    public static function query(array $p, string $handle = 'default'): StatsQuery {
        return new StatsQuery(
            window: TimeWindow::clamped($p['start'], $p['end']),
            sources: $p['sources'],
            profile: $p['profile'],
            for: $p['element'],
            orderBy: $p['order'],
            limit: $p['count'],
            filter: $p['filter'],
            lowerLimit: $p['lower'],
            upperLimit: $p['upper'],
            aggregation: $p['aggregation'],
            handle: $handle,
            protocol: $p['protocol'],
        );
    }

    /**
     * A side panel's query: the page's window, sources, filter and thresholds, its own element,
     * ranked by bytes, in csv for nfdump's share column.
     *
     * @param StatsParams $p
     */
    public static function panelQuery(array $p, string $panel, string $handle = 'default'): StatsQuery {
        return new StatsQuery(
            window: TimeWindow::clamped($p['start'], $p['end']),
            sources: $p['sources'],
            profile: $p['profile'],
            for: self::PANEL_ELEMENTS[$panel] ?? throw new \InvalidArgumentException('Unknown panel.'),
            orderBy: 'bytes',
            limit: self::PANEL_ROWS,
            filter: $p['filter'],
            lowerLimit: $p['lower'],
            upperLimit: $p['upper'],
            handle: $handle,
            protocol: $p['protocol'],
            output: 'csv',
        );
    }

    /** A live window is keyed by its width, so a result does not turn stale as it slides (1.7). */
    public static function windowKey(int $start, int $end, bool $live): string {
        if ($live) {
            return 'live:' . max(0, $end - $start);
        }
        $step = self::WINDOW_STEP;

        return intdiv($start, $step) * $step . '-' . intdiv($end, $step) * $step;
    }

    /**
     * What the global controls decide: window, sources, profile and protocol.
     *
     * @param StatsParams $p
     */
    public static function scope(array $p): string {
        return sha1(self::encode(self::scopeKey($p)));
    }

    /**
     * Every input of a statistic run (4.2.3).
     *
     * @param StatsParams $p
     */
    public static function fingerprint(array $p): string {
        $aggregation = $p['element'] === 'record' ? Nfdump::buildAggregationString($p['aggregation']) : '';

        return sha1(self::encode([...self::scopeKey($p), $p['filter'], $p['lower'], $p['upper'], $p['count'], $p['order'], $aggregation, $p['element']]));
    }

    /** @param StatsParams $p */
    public static function panelFingerprint(array $p, string $panel): string {
        return sha1(self::encode([...self::scopeKey($p), $p['filter'], $p['lower'], $p['upper'], $panel]));
    }

    /**
     * The inputs a page changes without posting, so the page can compare them in the browser.
     *
     * @param StatsParams $p
     *
     * @return StatsInputs
     */
    public static function inputs(array $p): array {
        return [
            'count' => $p['count'],
            'order' => $p['order'],
            'filter' => $p['filter'],
            'lower' => $p['lower'],
            'upper' => $p['upper'],
            'aggregation' => $p['element'] === 'record' ? $p['aggregation'] : [],
        ];
    }

    /**
     * @param StatsParams $p
     *
     * @return PanelInputs
     */
    public static function panelInputs(array $p): array {
        return ['filter' => $p['filter'], 'lower' => $p['lower'], 'upper' => $p['upper']];
    }

    /**
     * Stores a finished run under its statistic with its warnings (the clamp, nfdump's stderr),
     * so they come back with its table, and clears the page notices.
     *
     * @param array{}|StatsParams $params the run's inputs; none for a caller outside the page
     */
    public static function storeResult(TalkersState $state, QueryResult $result, float $elapsed, string $ipInfoUrl, string $clampNotice = '', array $params = []): void {
        $element = $params['element'] ?? 'record';
        $html = Table::generate($result->rows, 'statsTable', [
            'linkIpAddresses' => true,
            'ipInfoActionUrl' => $ipInfoUrl,
            'sources' => $params['sources'] ?? [],
            'originalData' => $result->rawOutput,
            'caption' => TalkersPage::resultTitle($element, $params['order'] ?? 'bytes'),
            'exportName' => 'top-talkers-' . $element,
        ]);
        $warnings = [];
        if ($clampNotice !== '') {
            $warnings[] = self::warning($clampNotice);
        }
        if ($result->stderr !== '') {
            $warnings[] = self::warning('nfdump warning:', $result->stderr);
        }
        $state->setResult($html, $element, [
            'command' => $result->command,
            'notes' => $result->notes,
            'warnings' => $warnings,
            'elapsed' => $elapsed,
            'rows' => $result->count(),
            'ts' => time(),
            'from' => $result->window->start,
            'to' => $result->window->end,
            'live' => $params['live'] ?? false,
            'fingerprint' => $params !== [] ? self::fingerprint($params) : '',
            'scope' => $params !== [] ? self::scope($params) : '',
            'inputs' => $params !== [] ? self::inputs($params) : TalkersState::emptyInputs(),
        ]);
        $state->clearNotifications();
    }

    /**
     * Stores a failed run of `$element`, which forgets its earlier run; a statistic outside the
     * catalog forgets none. A cancelled run keeps the earlier run and kill-nfdump's notice.
     */
    public static function storeFailure(TalkersState $state, \Throwable $e, bool $cancelled, string $element): void {
        $element = StatisticCatalog::isValid($element) ? $element : '';
        if ($cancelled) {
            $state->keepResult($element);
            $state->notify('info', QueryRunner::CANCELLED_STATUS);

            return;
        }
        Debug::getInstance()->log('Stats action error: ' . $e->getMessage(), LOG_ERR);
        $state->notifyFailure($e);
        $state->clearResult($element);
    }

    /**
     * @param list<StatRow>       $rows
     * @param array{}|StatsParams $params
     */
    public static function storePanel(TalkersState $state, string $panel, array $rows, QueryResult $result, float $elapsed, array $params = []): void {
        $state->setPanel($panel, [
            'bars' => self::bars($panel, $rows),
            'command' => $result->command,
            'error' => '',
            'elapsed' => $elapsed,
            'ts' => time(),
            'from' => $result->window->start,
            'to' => $result->window->end,
            'live' => $params['live'] ?? false,
            'fingerprint' => $params !== [] ? self::panelFingerprint($params, $panel) : '',
            'scope' => $params !== [] ? self::scope($params) : '',
            'inputs' => $params !== [] ? self::panelInputs($params) : ['filter' => '', 'lower' => '', 'upper' => ''],
            // The main result says the same for its own run; the panel covers its window alone.
            'clamp' => $result->window->clamped ? $result->window->clampNotice() : '',
        ]);
    }

    /**
     * A failed panel run shows its error in the panel. A cancelled one keeps the last bars, and
     * puts back the notices kill-nfdump replaced: the panel's Run control says it was cancelled.
     *
     * @param array{}|StatsParams     $params
     * @param null|list<Notification> $notices the page notices when the run started
     */
    public static function storePanelFailure(TalkersState $state, string $panel, \Throwable $e, bool $cancelled, array $params = [], ?array $notices = null): void {
        if ($cancelled) {
            if ($notices !== null) {
                $state->notifications = $notices;
            }

            return;
        }
        Debug::getInstance()->log('Top Talkers panel error: ' . $e->getMessage(), LOG_ERR);
        $state->setPanel($panel, [
            'bars' => [],
            'command' => $e instanceof NfdumpException ? $e->command : '',
            'error' => $e->getMessage(),
            'elapsed' => 0.0,
            'ts' => time(),
            'from' => 0,
            'to' => 0,
            'live' => $params['live'] ?? false,
            'fingerprint' => $params !== [] ? self::panelFingerprint($params, $panel) : '',
            'scope' => $params !== [] ? self::scope($params) : '',
            'inputs' => $params !== [] ? self::panelInputs($params) : ['filter' => '', 'lower' => '', 'upper' => ''],
            'clamp' => '',
        ]);
    }

    /**
     * Bars of a panel. Protocol rows that are a series of the picker graph (TCP, UDP, ICMP and
     * ICMPv6) take its slot; every other row stays neutral (2.3).
     *
     * @param list<StatRow> $rows
     *
     * @return list<PanelBar>
     */
    public static function bars(string $panel, array $rows): array {
        // `-s as` counts a flow at both ends, so its share goes half to each; bytes stay nfdump's.
        $ends = $panel === 'as' ? 2 : 1;
        $bars = [];
        foreach ($rows as $row) {
            $bars[] = [
                'key' => $row['key'],
                'label' => $panel === 'proto' ? self::protocolLabel($row['key'], $row['proto']) : self::asLabel($row['key']),
                'bytes' => $row['bytes'],
                'share' => $row['bytesPct'] !== null ? $row['bytesPct'] / $ends : null,
                'series' => $panel === 'proto' && ctype_digit($row['key']) ? (self::PROTOCOL_SLOTS[(int) $row['key']] ?? null) : null,
            ];
        }

        return $bars;
    }

    public static function protocolLabel(string $number, string $name): string {
        return $name !== '' && !ctype_digit($name) ? $name : 'Protocol ' . $number;
    }

    public static function asLabel(string $number): string {
        return $number === '0' ? 'AS0 (not exported)' : 'AS' . $number;
    }

    /** @return Notification */
    private static function warning(string $message, string $code = ''): array {
        return ['id' => TalkersState::newResultId(), 'type' => 'warning', 'message' => $message, 'code' => $code];
    }

    /**
     * @param StatsParams $p
     *
     * @return list<list<string>|string>
     */
    private static function scopeKey(array $p): array {
        return [self::windowKey($p['start'], $p['end'], $p['live']), $p['sources'], $p['profile'], $p['protocol']];
    }

    /** @param list<mixed> $parts */
    private static function encode(array $parts): string {
        return json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
