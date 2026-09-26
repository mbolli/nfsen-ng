<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Shell module for filter validation and query estimates per target (3.5.3): the
 * server-owned `_flt_<target>` and `_est_<target>` signals and the target table.
 */
final class QueryKit implements ShellModule {
    /**
     * Filter signal ('' = not validated), estimate kind ('' = none; the drawer borrows its
     * target's) and whether NFSEN_MAX_STATS_WINDOW clamps the estimate's window.
     */
    public const array TARGETS = [
        'overview' => ['filter' => 'graph_filter', 'kind' => 'graph', 'clamped' => true],
        'overview-topn' => ['filter' => '', 'kind' => 'overview-topn', 'clamped' => true],
        'talkers' => ['filter' => 'stats_filter', 'kind' => 'stats', 'clamped' => true],
        'flows' => ['filter' => 'flows_filter', 'kind' => 'flows', 'clamped' => false],
        'conversations' => ['filter' => 'sankey_filter', 'kind' => 'conversations', 'clamped' => true],
        'drawer' => ['filter' => 'drawer_filter', 'kind' => 'drawer', 'clamped' => true],
        'alert' => ['filter' => 'alert_form_nfdumpFilter', 'kind' => '', 'clamped' => false],
    ];

    public const array FILTER_DEFAULT = ['status' => '', 'message' => '', 'checked' => ''];

    public const array ESTIMATE_DEFAULT = [
        'pending' => true,
        'files' => 0,
        'bytes' => 0,
        'bytesHuman' => '',
        'runs' => 0,
        'seconds' => null,
        'secondsHuman' => '',
        'measured' => false,
        'clamped' => false,
        'window' => '',
        'heavy' => false,
    ];

    public static function signals(Context $c): void {
        foreach (self::TARGETS as $target => $meta) {
            if ($meta['filter'] !== '') {
                $c->signal(self::FILTER_DEFAULT, self::filterSignal($target));
            }
            if ($meta['kind'] !== '') {
                $c->signal(self::ESTIMATE_DEFAULT, self::estimateSignal($target));
            }
        }
    }

    public static function register(Context $c, Via $app, PageStates $states): void {}

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        $targets = [];
        foreach (self::TARGETS as $target => $meta) {
            $targets[$target] = [
                'filterSignal' => $meta['filter'],
                'kind' => $meta['kind'],
                'clamped' => $meta['clamped'],
                'validates' => $meta['filter'] !== '',
                'estimates' => $meta['kind'] !== '',
            ];
        }

        return ['targets' => $targets];
    }

    /** `_flt_<target>`, with '_' for '-'. */
    public static function filterSignal(string $target): string {
        return '_flt_' . str_replace('-', '_', $target);
    }

    /** `_est_<target>`, with '_' for '-'. */
    public static function estimateSignal(string $target): string {
        return '_est_' . str_replace('-', '_', $target);
    }
}
