<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\ConversationActions;
use mbolli\nfsen_ng\common\TableFormatter;
use mbolli\nfsen_ng\pages\state\ConversationsState;
use mbolli\nfsen_ng\query\MatrixQuery;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Conversations (was Sankey): who talks to whom in the range, as a Sankey, a Matrix and ranked IP pairs (4.4). */
final class ConversationsPage implements Page {
    /** A live result older than this says the window has moved on (1.7). */
    public const int LIVE_AGE = 300;

    public static function id(): string {
        return 'conversations';
    }

    public static function title(): string {
        return 'Conversations';
    }

    public static function lede(): string {
        return 'Who talks to whom in the selected range.';
    }

    public static function icon(): string {
        return 'arrows-exchange';
    }

    public static function group(): string {
        return 'analysis';
    }

    public static function signals(Context $c): void {
        $c->signal('', 'sankey_filter', clientWritable: true);
        $c->signal(20, 'sankey_topN', clientWritable: true);
        $c->signal('bytes', 'sankey_metric', clientWritable: true);
        // Byte thresholds, composed into the filter as bytes > / bytes <.
        $c->signal('', 'sankey_lower_limit', clientWritable: true);
        $c->signal('', 'sankey_upper_limit', clientWritable: true);
        $c->signal('ip', 'conv_group', clientWritable: true);
        $c->signal('both', 'conv_direction', clientWritable: true);
        $c->signal(false, '_conv_stale');
        // Pairs of the last run, for the query control's completion announcement.
        $c->signal(0, '_conv_pairs');
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        ConversationActions::register($c, $states);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $state = $states->conversations;
        $stale = ConversationActions::markStale($c, $state);
        $info = $state->info;

        return [
            'notifications' => $state->notifications,
            'result' => [
                'id' => $state->resultId,
                'send' => $state->sendResult('conversations', $state->resultId, $isUpdate),
            ],
            'has' => !$state->isEmpty(),
            'payload' => $state->payload,
            'tableHtml' => $state->tableHtml,
            'info' => $info,
            'summary' => self::summary($info),
            'others' => self::others($info),
            'seconds' => max(1, $info['end'] - $info['start']),
            'fetched' => MatrixQuery::fetchLimitFor('both', $info['topN']),
            'aged' => !$stale && self::aged($state, time()),
            'groups' => ConversationActions::GROUPS,
            'topNChoices' => ConversationActions::TOP_N_CHOICES,
        ];
    }

    /** A live result that is older than LIVE_AGE (1.7). */
    public static function aged(ConversationsState $state, int $now): bool {
        return !$state->isEmpty() && $state->info['live'] && $now - $state->info['at'] >= self::LIVE_AGE;
    }

    /**
     * The results card's line, e.g. "Top 20 pairs = 64% of bytes".
     *
     * @param array{pairs: int, share: ?float, metric: string} $info
     */
    public static function summary(array $info): string {
        $pairs = $info['pairs'];
        if ($pairs === 0) {
            return 'No pairs';
        }
        $what = $pairs === 1 ? 'Top pair' : 'Top ' . number_format($pairs) . ' pairs';

        return $info['share'] === null
            ? $what . ' by ' . $info['metric']
            : $what . ' = ' . ConversationActions::percent($info['share']) . ' of ' . $info['metric'];
    }

    /**
     * The IP pairs footer, e.g. "Others (not in top 20): 1.200 GB, 4,512 packets, 310 flows, 36% of bytes",
     * with bytes as the table above prints them; '' when the totals are unknown or nothing is left over.
     *
     * @param array{topN: int, metric: string, others: null|array{bytes: int, packets: int, flows: int, share: float}} $info
     */
    public static function others(array $info): string {
        $o = $info['others'];
        if ($o === null || $o['bytes'] + $o['packets'] + $o['flows'] === 0) {
            return '';
        }

        return 'Others (not in top ' . number_format($info['topN']) . '): ' . TableFormatter::formatCellValue($o['bytes'], 'bytes', [])
            . ', ' . number_format($o['packets']) . ' packets, ' . number_format($o['flows']) . ' flows, '
            . ConversationActions::percent($o['share']) . ' of ' . $info['metric'];
    }
}
