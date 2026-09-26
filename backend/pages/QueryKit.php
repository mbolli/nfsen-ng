<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\QueryKitActions;
use mbolli\nfsen_ng\actions\QueryRunner;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Filter validation and query estimates per target (3.5.3): `_flt_<t>`, `_est_<t>` and their actions. */
final class QueryKit implements ShellModule {
    /** Filter signal ('' = none), estimate kind ('' = none) and whether NFSEN_MAX_STATS_WINDOW clamps. */
    public const array TARGETS = [
        'overview' => ['filter' => 'graph_filter', 'kind' => 'graph', 'clamped' => true],
        'overview-topn' => ['filter' => '', 'kind' => 'overview-topn', 'clamped' => true],
        'talkers' => ['filter' => 'stats_filter', 'kind' => 'stats', 'clamped' => true],
        'flows' => ['filter' => 'flows_filter', 'kind' => 'flows', 'clamped' => false],
        'conversations' => ['filter' => 'sankey_filter', 'kind' => 'conversations', 'clamped' => true],
        'drawer' => ['filter' => 'drawer_filter', 'kind' => 'drawer', 'clamped' => true],
        'alert' => ['filter' => 'alert_form_nfdumpFilter', 'kind' => '', 'clamped' => false],
    ];

    /** @var array{status: ''|'invalid'|'valid', message: string, checked: string} */
    public const array FILTER_DEFAULT = ['status' => '', 'message' => '', 'checked' => ''];

    /**
     * An answered estimate always has a `window`.
     *
     * @var array{pending: bool, files: int, bytes: int, bytesHuman: string, runs: int, seconds: ?int, secondsHuman: string,
     *            measured: bool, clamped: bool, window: string, heavy: bool}
     */
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

    public static function register(Context $c, Via $app, PageStates $states): void {
        QueryKitActions::register($c, $app);
    }

    /**
     * Per target: its signals' wire ids, and whether a run may stop early (the estimate says "up to").
     *
     * @return array{targets: array<string, array{filterSignal: string, kind: string, clamped: bool, validates: bool,
     *               estimates: bool, filterId: string, estimateId: string, earlyStop: bool}>,
     *               defaults: array{filter: array<string, mixed>, estimate: array<string, mixed>}}
     */
    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        $targets = [];
        foreach (self::TARGETS as $target => $meta) {
            $targets[$target] = [
                'filterSignal' => $meta['filter'],
                'kind' => $meta['kind'],
                'clamped' => $meta['clamped'],
                'validates' => $meta['filter'] !== '',
                'estimates' => $meta['kind'] !== '',
                'filterId' => $meta['filter'] !== '' ? ($c->getSignal(self::filterSignal($target))?->id() ?? '') : '',
                'estimateId' => $meta['kind'] !== '' ? ($c->getSignal(self::estimateSignal($target))?->id() ?? '') : '',
                'earlyStop' => \in_array($meta['kind'], QueryRunner::EARLY_STOP_KINDS, true),
            ];
        }

        return [
            'targets' => $targets,
            'defaults' => ['filter' => self::FILTER_DEFAULT, 'estimate' => self::ESTIMATE_DEFAULT],
        ];
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
