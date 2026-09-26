<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Keeps per-tab results across a context revival (#151), which re-runs the page handler under
 * the same context id; a reload gets a new id and starts clean.
 */
final class Revival {
    public const string KEY = 'revive_snapshots';

    /** Mirrors php-via's own cap on revivable contexts. */
    public const int MAX_CONTEXTS = 200;

    /** @var null|\WeakMap<PageStates, true> states whose first render has passed */
    private static ?\WeakMap $seen = null;

    public static function restore(Context $c, Via $app, PageStates $states): void {
        self::$seen ??= new \WeakMap();
        if (isset(self::$seen[$states])) {
            return;
        }
        self::$seen[$states] = true;

        $snapshot = self::load($app)[$c->getId()] ?? null;
        if ($snapshot !== null) {
            self::restoreInto($states, $snapshot, $c);
        }
    }

    public static function persist(Context $c, Via $app, PageStates $states): void {
        $snapshots = self::merge(self::load($app), $c->getId(), self::snapshotOf($states));
        if ($snapshots !== null) {
            $app->setGlobalState(self::KEY, $snapshots);
        }
    }

    /**
     * Restores into every state that is still empty.
     *
     * @param array<string, array<string, mixed>> $snapshot page id (or 'shell') => state snapshot
     */
    public static function restoreInto(PageStates $states, array $snapshot, ?Context $c = null): void {
        foreach ($states->all() as $id => $state) {
            if (!isset($snapshot[$id]) || !$state->isEmpty()) {
                continue;
            }
            $state->restore($snapshot[$id]);
            if ($c !== null) {
                $state->restoreSignals($c);
            }
        }
    }

    /** @return array<string, array<string, mixed>> the non-empty states, keyed like PageStates::all() */
    public static function snapshotOf(PageStates $states): array {
        $snapshot = [];
        foreach ($states->all() as $id => $state) {
            if (!$state->isEmpty()) {
                $snapshot[$id] = $state->snapshot();
            }
        }

        return $snapshot;
    }

    /**
     * The snapshot map with this context's entry replaced, or null when nothing changed.
     * An updated context moves to the end, so the cap evicts the ones idle the longest.
     *
     * @param array<string, array<string, array<string, mixed>>> $snapshots
     * @param array<string, array<string, mixed>>                $current
     *
     * @return null|array<string, array<string, array<string, mixed>>>
     */
    public static function merge(array $snapshots, string $ctxId, array $current): ?array {
        $previous = $snapshots[$ctxId] ?? [];
        if ($previous === $current) {
            return null;
        }

        unset($snapshots[$ctxId]);
        if ($current !== []) {
            $snapshots[$ctxId] = $current;
        }

        return \count($snapshots) > self::MAX_CONTEXTS
            ? \array_slice($snapshots, -self::MAX_CONTEXTS, null, true)
            : $snapshots;
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private static function load(Via $app): array {
        $snapshots = $app->globalState(self::KEY, []);
        if (!\is_array($snapshots)) {
            return [];
        }

        $valid = [];
        foreach ($snapshots as $ctxId => $snapshot) {
            if (\is_string($ctxId) && \is_array($snapshot)) {
                $valid[$ctxId] = array_filter($snapshot, 'is_array');
            }
        }

        /** @var array<string, array<string, array<string, mixed>>> $valid */
        return $valid;
    }
}
