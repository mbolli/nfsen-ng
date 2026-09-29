<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\state\ConversationsState;
use mbolli\nfsen_ng\processor\NfdumpSummary;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\ConversationPayload;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;

/**
 * Conversations (4.4): `conversations-run` aggregates the range into ranked pairs for the
 * Sankey, the Matrix and the IP pairs table; `conversations-check` only compares the query
 * card with the stored result, so the stale notice follows edits without a run.
 *
 * @phpstan-import-type Payload from ConversationPayload
 * @phpstan-import-type Notification from \mbolli\nfsen_ng\pages\state\PageState
 *
 * @phpstan-type Params array{group: string, direction: string, fallback: bool, metric: string, topN: int, filter: string,
 *                            lower: string, upper: string, start: int, end: int, live: bool, sources: list<string>,
 *                            protocol: string, profile: string}
 */
final class ConversationActions {
    /** @var list<int> the Top pairs choices; the server accepts up to MatrixQuery::MAX_TOP_N */
    public const array TOP_N_CHOICES = [10, 20, 50, 100, 200];

    /** @var array<string, string> Group by, in the order the select lists them */
    public const array GROUPS = [
        'ip' => 'IP address',
        'net24' => '/24 subnet',
        'net16' => '/16 subnet',
        'port' => 'Destination port',
    ];

    public const string FALLBACK_NOTICE = 'Both directions cannot be merged by destination port, so this run shows source to destination only.';

    public static function register(Context $c, PageStates $states): void {
        $conversations = $states->conversations;

        $c->action(static function (Context $c) use ($conversations): void {
            self::run($c, $conversations);
        }, 'conversations-run');

        $c->action(static function (Context $c) use ($conversations): void {
            self::markStale($c, $conversations);
            $c->syncSignals();
        }, 'conversations-check');
    }

    /**
     * The query card and the globals, normalised whatever the client sent. Direction 'both'
     * with the port grouping falls back to 'forward' (D15).
     *
     * @return Params
     */
    public static function params(Context $c): array {
        $group = $c->getSignal('conv_group')?->string() ?? 'ip';
        $group = isset(self::GROUPS[$group]) ? $group : 'ip';
        $direction = $c->getSignal('conv_direction')?->string() === 'forward' ? 'forward' : 'both';
        $fallback = $direction === 'both' && $group === 'port';
        $sources = $c->getSignal('graph_sources')?->array() ?? [];

        return [
            'group' => $group,
            'direction' => $fallback ? 'forward' : $direction,
            'fallback' => $fallback,
            'metric' => $c->getSignal('sankey_metric')?->string() === 'packets' ? 'packets' : 'bytes',
            'topN' => max(1, min(MatrixQuery::MAX_TOP_N, $c->getSignal('sankey_topN')?->int() ?? 20)),
            'filter' => trim($c->getSignal('sankey_filter')?->string() ?? ''),
            'lower' => trim($c->getSignal('sankey_lower_limit')?->string() ?? ''),
            'upper' => trim($c->getSignal('sankey_upper_limit')?->string() ?? ''),
            'start' => $c->getSignal('datestart')?->int() ?? 0,
            'end' => $c->getSignal('dateend')?->int() ?? 0,
            'live' => $c->getSignal('range_live')?->bool() ?? false,
            'sources' => Helpers::resolveSources($sources),
            'protocol' => RangeControls::protocol($c),
            'profile' => $c->getSignal('selected_profile')?->string() ?? '',
        ];
    }

    /**
     * What a result was computed for. A live window counts by its width, so a result does not
     * go stale while the window slides (1.7).
     *
     * @param Params $p
     */
    public static function fingerprint(array $p): string {
        $sources = $p['sources'];
        sort($sources);
        $window = $p['live']
            ? 'live:' . max(0, $p['end'] - $p['start'])
            : intdiv($p['start'], 300) . '-' . intdiv($p['end'], 300);

        return json_encode([
            $p['group'], $p['direction'], $p['metric'], $p['topN'], $p['filter'], $p['lower'], $p['upper'],
            $window, $sources, $p['protocol'], $p['profile'],
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Sets `_conv_stale`: a result is stored and the inputs no longer match its fingerprint. */
    public static function markStale(Context $c, ConversationsState $state): bool {
        $stale = !$state->isEmpty() && self::fingerprint(self::params($c)) !== $state->info['fingerprint'];
        $c->getSignal('_conv_stale')?->setValue($stale, broadcast: false);

        return $stale;
    }

    /**
     * Stores a finished run: the payload, the IP pairs table, and what the results card says.
     *
     * @param Params $p
     */
    public static function storeResult(ConversationsState $state, MatrixQuery $query, QueryResult $result, array $p, float $elapsed, string $ipInfoUrl, string $clampNotice = '', ?int $now = null): void {
        $raw = \is_string($result->rawOutput) ? $result->rawOutput : '';
        $payload = ConversationPayload::build(
            $result->rows,
            $query->metric(),
            $query->groupBy(),
            $query->direction,
            $query->topN,
            ConversationPayload::totalsFrom(NfdumpSummary::fromTextFooter($raw)),
            $result->command,
        );
        $pairs = $payload['pairs'];
        $metric = $payload['meta']['metric'];
        $total = $payload['totals'][$metric] ?? 0;

        $state->setResult(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $pairs === [] ? '' : Table::generate(self::tableRows($payload), 'conversationsTable', [
                'rankColumn' => 'rank',
                'rankSeries' => self::rankSeries($payload),
                'linkIpAddresses' => true,
                'ipInfoActionUrl' => $ipInfoUrl,
                'originalData' => $raw,
                'caption' => 'IP pairs',
                'exportName' => 'conversations-pairs',
            ]),
            [
                'pairs' => \count($pairs),
                'share' => $total > 0 ? min(1.0, array_sum(array_column($pairs, $metric)) / $total) : null,
                'metric' => $metric,
                'groupBy' => $payload['meta']['groupBy'],
                'direction' => $payload['meta']['direction'],
                'topN' => $payload['meta']['topN'],
                'approximate' => $payload['meta']['approximate'],
                'command' => $result->command,
                'start' => $result->window->start,
                'end' => $result->window->end,
                'live' => $p['live'],
                'at' => $now ?? time(),
                'fingerprint' => self::fingerprint($p),
                'others' => $payload['others'],
            ],
        );

        $state->notifyResult($result, $elapsed, 'Conversations');
        if ($clampNotice !== '') {
            $state->notify('warning', $clampNotice);
        }
        if ($p['fallback']) {
            $state->notify('info', self::FALLBACK_NOTICE);
        }
    }

    /**
     * Stores a failed run. A cancelled run keeps the previous result, and with it the notices
     * it had when the run started ($notices).
     *
     * @param list<Notification> $notices
     */
    public static function storeFailure(ConversationsState $state, \Throwable $e, bool $cancelled, array $notices = []): void {
        if ($cancelled) {
            self::restoreNotices($state, $notices);

            return;
        }
        Debug::getInstance()->log('Conversations action error: ' . $e->getMessage(), LOG_ERR);
        $state->notifyFailure($e);
        $state->clearResult();
    }

    /**
     * Puts back the kept result's notices after a cancelled run, below kill-nfdump's notice
     * when it wrote one.
     *
     * @param list<Notification> $notices
     */
    public static function restoreNotices(ConversationsState $state, array $notices): void {
        $state->notifications = \array_slice([...$state->notifications, ...$notices], 0, ConversationsState::MAX_NOTIFICATIONS);
    }

    /**
     * The IP pairs table: rank, both ends (addresses link to the IP info), the port for the
     * port grouping, the figures and the share of the total as a fraction, which the table
     * prints as a percentage and exports raw.
     *
     * @param Payload $payload
     *
     * @return list<array<string, float|int|string>>
     */
    public static function tableRows(array $payload): array {
        $subnet = \in_array($payload['meta']['groupBy'], ['net24', 'net16'], true);
        $rows = [];
        foreach ($payload['pairs'] as $pair) {
            $row = ['rank' => $pair['rank']];
            $row[$subnet ? 'srcNet' : 'srcip'] = $pair['src'];
            $row[$subnet ? 'dstNet' : 'dstip'] = $pair['dst'];
            if ($pair['port'] !== null) {
                // The table would format ICMP's type.code as a port number.
                $row['dstport'] = \is_string($pair['port']) ? 'ICMP ' . $pair['port'] : $pair['port'];
            }
            $row['bytes'] = $pair['bytes'];
            $row['packets'] = $pair['packets'];
            $row['flows'] = $pair['flows'];
            $row['share_pct'] = $pair['share'] === null ? '' : round($pair['share'], 6);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Runs the query, split into parallel nfdump processes when that pays; null when Kill was
     * pressed before nfdump started or before the result is stored, so a cancelled run never
     * replaces the result.
     *
     * @param \Closure(): bool                                                  $cancelled
     * @param null|\Closure(int, \Closure(): array{int, int}, bool, bool): void $onSplit   QueryRunner's, for the progress of a split
     */
    public static function runUnlessCancelled(MatrixQuery $query, Processor $processor, \Closure $cancelled, ?\Closure $onSplit = null): ?QueryResult {
        if ($cancelled()) {
            return null;
        }
        $result = $query->runPartitioned($processor, $onSplit, $cancelled);

        return $cancelled() ? null : $result; // @phpstan-ignore ternary.alwaysFalse (Kill can land while nfdump runs)
    }

    /** "64%", or one decimal below 10 %. */
    public static function percent(float $share): string {
        $pct = $share * 100;

        return number_format($pct, $pct > 0 && $pct < 10 ? 1 : 0) . '%';
    }

    /**
     * Rank => series slot of the rows whose source node is coloured in the charts (2.3).
     *
     * @param Payload $payload
     *
     * @return array<int, int>
     */
    private static function rankSeries(array $payload): array {
        $series = [];
        foreach ($payload['pairs'] as $pair) {
            if ($pair['series'] !== null) {
                $series[$pair['rank']] = $pair['series'];
            }
        }

        return $series;
    }

    private static function run(Context $c, ConversationsState $state): void {
        $p = self::params($c);
        $contextId = $c->getId();
        $ipInfoUrl = $c->getAction('ip-info')?->url() ?? '';
        $pairsSignal = $c->getSignal('_conv_pairs');
        if ($p['fallback']) {
            $c->getSignal('conv_direction')?->setValue('forward', broadcast: false);
        }
        $time = microtime(true);
        $notices = $state->notifications;
        $state->clearNotifications();

        try {
            $query = new MatrixQuery(
                window: TimeWindow::clamped($p['start'], $p['end']),
                sources: $p['sources'],
                profile: $p['profile'],
                metric: $p['metric'],
                topN: $p['topN'],
                filter: $p['filter'],
                lowerLimit: $p['lower'],
                upperLimit: $p['upper'],
                handle: $contextId,
                protocol: $p['protocol'],
                groupBy: $p['group'],
                direction: $p['direction'],
            );
            $clampNotice = $query->window->clamped ? $query->window->clampNotice() : '';
            // Sized in the coroutine: walking the window belongs behind the response.
            $totalBytes = static fn (): int => $query->totalBytes();
            $processor = $query->processor();

            QueryRunner::run($c, 'conversations', $totalBytes, 'Starting nfdump…', static function (?\Closure $onSplit = null) use (
                $query,
                $processor,
                $p,
                $state,
                $time,
                $ipInfoUrl,
                $clampNotice,
                $contextId,
                $pairsSignal,
                $notices
            ): void {
                try {
                    $result = self::runUnlessCancelled($query, $processor, static fn (): bool => QueryCancel::isRequested($contextId), $onSplit);
                    if ($result === null) {
                        self::restoreNotices($state, $notices);

                        return;
                    }
                    self::storeResult($state, $query, $result, $p, round(microtime(true) - $time, 3), $ipInfoUrl, $clampNotice);
                    $pairsSignal?->setValue($state->info['pairs'], broadcast: false);
                } catch (\Throwable $e) {
                    // Read here: QueryRunner clears the cancel flag after the work.
                    self::storeFailure($state, $e, QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId)), $notices);
                    $pairsSignal?->setValue($state->info['pairs'], broadcast: false);

                    // QueryRunner owns the status line ("Failed: ..." or "Query cancelled.").
                    throw $e;
                }
            });
        } catch (\Throwable $e) {
            // Nothing was started (bad window, filter, profile), so the failure is reported here.
            self::storeFailure($state, $e, false);
            $pairsSignal?->setValue(0, broadcast: false);
            $c->sync();
        }
    }
}
