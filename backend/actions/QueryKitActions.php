<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\processor\FilterValidator;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\query\QueryEstimator;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * validate-filter and estimate-query (3.5.3), answered with signal-only patches. Closures catch
 * \Throwable: php-via only catches \Exception, and an escaped \Error kills the worker.
 */
final class QueryKitActions {
    /** Graph resolution when the Overview signals are missing. */
    private const int DEFAULT_POINTS = 500;

    /** @var null|\WeakMap<Context, array<string, int>> newest request per tab and answer signal */
    private static ?\WeakMap $tickets = null;

    public static function register(Context $c, Via $app): void {
        $c->action(static function (Context $c) use ($app): void {
            try {
                self::validate($c, self::inputTarget($c));
            } catch (\Throwable $e) {
                self::fail($c, 'Could not check the filter', $e);
            }
            self::push($c, $app);
        }, 'validate-filter');

        $c->action(static function (Context $c) use ($app): void {
            try {
                self::estimate($c, self::inputTarget($c), $app);
            } catch (\Throwable $e) {
                self::fail($c, 'Could not estimate the query', $e);
                self::push($c, $app);
            }
        }, 'estimate-query');
    }

    /**
     * `nfdump -Z` into `_flt_<target>`, newest request only; a failed check answers "could not be
     * checked". A target without both signals in this tab is ignored.
     */
    public static function validate(Context $c, string $target, ?string $binary = null): void {
        $name = QueryKit::TARGETS[$target]['filter'] ?? '';
        $key = QueryKit::filterSignal($target);
        $filter = $name !== '' ? $c->getSignal($name) : null;
        $result = $name !== '' ? $c->getSignal($key) : null;
        if ($filter === null || $result === null) {
            return;
        }

        // Read once: the check yields, and a later keystroke may replace the signal meanwhile.
        $text = self::text($filter);
        $ticket = self::nextTicket($c, $key);
        $error = null;

        try {
            $answer = trim($text) === '' ? ['valid' => true, 'message' => ''] : FilterValidator::validate($text, $binary);
        } catch (\Throwable $e) {
            $answer = ['valid' => false, 'message' => FilterValidator::UNCHECKED];
            $error = $e;
        }
        // A request that overtook this one has answered newer text; this answer would undo it.
        if (self::isNewest($c, $key, $ticket)) {
            $result->setValue(self::filterPayload($text, $answer), broadcast: false);
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * `_flt_<target>`: `checked` is the text the answer belongs to, so the field ignores an
     * answer for text that has changed since. An empty filter has no status.
     *
     * @param array{valid: bool, message: string} $answer
     *
     * @return array{status: ''|'invalid'|'valid', message: string, checked: string}
     */
    public static function filterPayload(string $filter, array $answer): array {
        if (trim($filter) === '') {
            return ['status' => '', 'message' => '', 'checked' => $filter];
        }

        return [
            'status' => $answer['valid'] ? 'valid' : 'invalid',
            'message' => $answer['valid'] ? '' : $answer['message'],
            'checked' => $filter,
        ];
    }

    /**
     * Marks `_est_<target>` pending and pushes that, then computes the estimate in a
     * coroutine. Only the newest request per target writes its answer.
     */
    public static function estimate(Context $c, string $target, ?Via $app = null): void {
        $key = QueryKit::estimateSignal($target);
        $signal = $c->getSignal($key);
        if ($signal === null) {
            return;
        }
        $ticket = self::nextTicket($c, $key);
        $plan = self::plan($c, $target);
        if ($plan === null) {
            // Nothing to estimate (a drawer opened for an alert rule): no window, so the component hides.
            $signal->setValue([...QueryKit::ESTIMATE_DEFAULT, 'pending' => false], broadcast: false);
            self::push($c, $app);

            return;
        }

        $signal->setValue([...self::current($signal), 'pending' => true], broadcast: false);
        self::push($c, $app);

        Coroutine::create(static function () use ($c, $app, $key, $plan, $signal, $ticket): void {
            try {
                $estimate = self::compute($plan);
                if (!self::isNewest($c, $key, $ticket)) {
                    return;
                }
                $signal->setValue(self::estimatePayload($estimate), broadcast: false);
                self::push($c, $app);
            } catch (\Throwable $e) {
                if (self::isNewest($c, $key, $ticket)) {
                    $signal->setValue([...self::current($signal), 'pending' => false], broadcast: false);
                    self::fail($c, 'Could not estimate the query', $e);
                    self::push($c, $app);
                }
            }
        });
    }

    /**
     * The kind, window, sources and profile an estimate for $target reads, plus resolution and
     * groups for the filtered graph. Null for a target without an estimate.
     *
     * @return null|array{target: string, kind: string, window: TimeWindow, sources: list<string>, profile: string, points: int, groups: int, splittable: ?bool}
     */
    public static function plan(Context $c, string $target): ?array {
        $resolved = self::estimateTarget($c, $target);
        if ($resolved === null) {
            return null;
        }
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        if ($datestart === null || $dateend === null) {
            return null;
        }

        $meta = QueryKit::TARGETS[$resolved];
        $start = $datestart->int();
        $end = max($start, $dateend->int());
        $sources = self::sources($c);
        $display = $c->getSignal('graph_display')?->string() ?? 'sources';
        $points = $c->getSignal('graph_resolution')?->int() ?? self::DEFAULT_POINTS;

        return [
            'target' => $resolved,
            'kind' => $meta['kind'],
            'window' => $meta['clamped'] ? TimeWindow::clamped($start, $end) : TimeWindow::raw($start, $end),
            'sources' => $sources,
            'profile' => self::profile($c),
            'points' => $points > 0 ? $points : self::DEFAULT_POINTS,
            // The filtered graph builds one series per source in the Sources display, else one.
            'groups' => $display === 'sources' ? max(1, \count($sources)) : 1,
            'splittable' => $resolved === 'talkers' ? self::talkersSplittable($c) : null,
        ];
    }

    /**
     * Walks the capture tree on a QueryEstimator cache miss: coroutine only.
     *
     * @param array{target: string, kind: string, window: TimeWindow, sources: list<string>, profile: string, points: int, groups: int, splittable?: ?bool} $plan
     */
    public static function compute(array $plan): Estimate {
        return $plan['kind'] === 'graph'
            ? QueryEstimator::filteredSeries($plan['kind'], $plan['window'], $plan['sources'], $plan['profile'], $plan['points'], $plan['groups'])
            : QueryEstimator::singlePass($plan['kind'], $plan['window'], $plan['sources'], $plan['profile'], $plan['splittable'] ?? null);
    }

    /**
     * `_est_<target>`, the shape the query-estimate component seeds (QueryKit::ESTIMATE_DEFAULT).
     *
     * @return array{pending: bool, files: int, bytes: int, bytesHuman: string, runs: int, seconds: ?int, secondsHuman: string,
     *               measured: bool, clamped: bool, window: string, heavy: bool}
     */
    public static function estimatePayload(Estimate $estimate): array {
        return ['pending' => false, ...$estimate->toArray()];
    }

    /**
     * The target whose kind and window an estimate for $target uses: the drawer borrows the
     * one it was opened for. Null when there is nothing to estimate.
     */
    public static function estimateTarget(Context $c, string $target): ?string {
        if ($target === 'drawer') {
            $target = $c->getSignal('drawer_target')?->string() ?? '';
            if ($target === 'drawer') {
                return null;
            }
        }
        $kind = QueryKit::TARGETS[$target]['kind'] ?? '';

        return \array_key_exists($kind, QueryEstimator::DEFAULT_THROUGHPUT) ? $target : null;
    }

    /** Whether Top Talkers' current query can split (StatsQuery::splittable()); null for one outside the catalog. */
    private static function talkersSplittable(Context $c): ?bool {
        try {
            return StatsActions::query(StatsActions::params($c))->splittable();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** `?target=` of the action request, '' when missing. */
    private static function inputTarget(Context $c): string {
        $target = $c->input('target');

        return \is_string($target) ? $target : '';
    }

    /**
     * Only configured sources reach the path walk: graph_sources is client-writable.
     *
     * @return list<string>
     */
    private static function sources(Context $c): array {
        $configured = Config::$settings->sources;
        $selected = Helpers::resolveSources($c->getSignal('graph_sources')?->array() ?? []);
        $known = array_values(array_intersect($selected, $configured));

        return $known !== [] ? $known : $configured;
    }

    /** The selected profile when it is one the server listed, else the configured one. */
    private static function profile(Context $c): string {
        $selected = $c->getSignal('selected_profile')?->string() ?? '';
        $available = $c->getSignal('available_profiles')?->array() ?? [];

        return \in_array($selected, $available, true) ? $selected : Config::$settings->nfdumpProfile;
    }

    /** A client-posted value that is not a string (a crafted array) reads as no filter. */
    private static function text(Signal $filter): string {
        $value = $filter->getValue();

        return \is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function current(Signal $signal): array {
        $value = $signal->getValue();

        /** @var array<string, mixed> */
        return \is_array($value) ? [...QueryKit::ESTIMATE_DEFAULT, ...$value] : QueryKit::ESTIMATE_DEFAULT;
    }

    private static function nextTicket(Context $c, string $signal): int {
        self::$tickets ??= new \WeakMap();
        $tickets = self::$tickets[$c] ?? [];
        $tickets[$signal] = ($tickets[$signal] ?? 0) + 1;
        self::$tickets[$c] = $tickets;

        return $tickets[$signal];
    }

    private static function isNewest(Context $c, string $signal, int $ticket): bool {
        return (self::$tickets[$c][$signal] ?? 0) === $ticket;
    }

    /**
     * Before this tab's SSE stream is up its patch channel is replaced on connect, which would
     * drop the patch; the changed signals then go out with the connect sync instead.
     */
    private static function push(Context $c, ?Via $app): void {
        if ($app !== null && ($app->activeSseCount[$c->getId()] ?? 0) === 0) {
            return;
        }
        $c->syncSignals();
    }

    private static function fail(Context $c, string $what, \Throwable $e): void {
        Debug::getInstance()->log($what . ': ' . $e->getMessage(), LOG_ERR);
        $c->getSignal('_error')?->setValue($what . ': ' . $e->getMessage(), broadcast: false);
    }
}
