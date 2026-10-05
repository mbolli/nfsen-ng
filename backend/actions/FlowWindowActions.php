<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Revival;
use mbolli\nfsen_ng\pages\state\FlowRowStore;
use mbolli\nfsen_ng\pages\state\FlowsState;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * The Flows list's requests, each one element patch of the host from FlowRowStore. They post only
 * via_ctx, so they read FlowsState and never a signal, and they work in a context they revived.
 */
final class FlowWindowActions {
    public static function register(Context $c, Via $app, PageStates $states): void {
        $c->action(static function (Context $c) use ($app, $states): void {
            Revival::restore($c, $app, $states);
            self::window($c, $states->flows);
        }, 'flows-window');

        $c->action(static function (Context $c) use ($app, $states): void {
            Revival::restore($c, $app, $states);
            if (self::sort($c, $states->flows)) {
                Revival::persist($c, $app, $states);
            }
        }, 'flows-sort');

        $c->action(static function (Context $c) use ($app, $states): void {
            Revival::restore($c, $app, $states);
            if (self::columns($c, $states->flows)) {
                Revival::persist($c, $app, $states);
            }
        }, 'flows-columns');
    }

    /** Rows offset .. offset + count - 1 of the list in the tab's order and columns, as the host with those rows. */
    public static function window(Context $c, FlowsState $flows): void {
        $result = self::current($c, $flows);
        $offset = $c->input('offset');
        $count = $c->input('count');
        if ($result === null || !\is_string($offset) || !ctype_digit($offset) || !\is_string($count) || !ctype_digit($count)) {
            return;
        }
        if ($flows->currentOrder() === null || !FlowRowStore::has($result)) {
            self::lost($c, $flows);

            return;
        }
        $total = FlowRowStore::count($result);
        $flows->lastOffset = min((int) $offset, $total);
        $flows->lastCount = (int) min((int) $count, FlowRows::MAX_WINDOW);
        if ($flows->firstRows === '') {
            // A revival dropped them: the next render that sends the list starts with rows again.
            $flows->firstRows = self::rows($flows, 0, FlowRows::INITIAL_ROWS)['html'] ?? '';
        }
        if (!self::answer($c, $flows, $flows->lastOffset, $flows->lastCount)) {
            self::lost($c, $flows);
        }
    }

    /**
     * Sorts the list by the column `key` in the direction `dir` ('asc' or 'desc'), stable against
     * the tab's order. Answers with the rows from the top, as many as the last window held.
     */
    public static function sort(Context $c, FlowsState $flows): bool {
        $result = self::current($c, $flows);
        $key = $c->input('key');
        $dir = $c->input('dir');
        if ($result === null || !\is_string($key) || !\in_array($dir, ['asc', 'desc'], true)) {
            return false;
        }
        $columns = FlowRowStore::columns($result);
        $current = $flows->currentOrder();
        if ($columns === null || $current === null) {
            self::lost($c, $flows);

            return false;
        }
        if (!\in_array($key, $columns['keys'], true)) {
            return false;
        }
        $order = FlowRowStore::order($result, $key, $dir, $current);
        if ($order === null) {
            self::lost($c, $flows);

            return false;
        }
        $flows->sortBy($key, $dir, $order);
        $flows->lastOffset = 0;
        $flows->lastCount = min(FlowRows::MAX_WINDOW, max($flows->lastCount, FlowRows::INITIAL_ROWS));
        $flows->firstRows = self::rows($flows, 0, FlowRows::INITIAL_ROWS)['html'] ?? '';
        if (!self::answer($c, $flows, 0, $flows->lastCount)) {
            self::lost($c, $flows);

            return false;
        }

        return true;
    }

    /**
     * Hides the columns `hidden` names (comma-separated keys, the whole set) and answers with the
     * window the list holds, in the new columns.
     */
    public static function columns(Context $c, FlowsState $flows): bool {
        $result = self::current($c, $flows);
        $hidden = $c->input('hidden');
        if ($result === null || (!\is_string($hidden) && !\is_array($hidden))) {
            return false;
        }
        if ($flows->currentOrder() === null || !FlowRowStore::has($result)) {
            self::lost($c, $flows);

            return false;
        }
        $flows->hiddenColumns = FlowsState::hiddenFrom($hidden, FlowRowStore::columns($result)['keys'] ?? []);
        $flows->firstRows = self::rows($flows, 0, FlowRows::INITIAL_ROWS)['html'] ?? '';
        $count = $flows->lastCount > 0 ? $flows->lastCount : FlowRows::INITIAL_ROWS;
        if (!self::answer($c, $flows, $flows->lastOffset, $count)) {
            self::lost($c, $flows);

            return false;
        }

        return true;
    }

    /**
     * After a list result was stored: the Run's sort when the result has that column, else file
     * order; the Run's hidden columns; and the first rows for the render that sends the list.
     *
     * @param list<string> $hidden
     */
    public static function prepare(FlowsState $flows, string $sortKey, string $sortDir, array $hidden): void {
        $columns = FlowRowStore::columns($flows->resultId);
        if ($columns === null) {
            return;
        }
        $flows->keepColumns();
        $flows->unsorted();
        if ($sortKey !== '' && \in_array($sortKey, $columns['keys'], true)) {
            $dir = $sortDir === 'desc' ? 'desc' : 'asc';
            $order = FlowRowStore::order($flows->resultId, $sortKey, $dir);
            if ($order !== null) {
                $flows->sortBy($sortKey, $dir, $order);
            }
        }
        $flows->hiddenColumns = FlowsState::hiddenFrom($hidden, $columns['keys']);
        $flows->firstRows = self::rows($flows, 0, FlowRows::INITIAL_ROWS)['html'] ?? '';
    }

    /** The host of the tab's result as a render places it: in full when the client lacks it, else the placeholder. */
    public static function hostHtml(Context $c, FlowsState $flows, bool $send): string {
        if (!$send) {
            return FlowRows::placeholder($flows->resultId);
        }
        $columns = FlowRowStore::columns($flows->resultId);
        if ($columns === null) {
            return '';
        }
        $shown = $flows->shownKeys();

        return FlowRows::host([
            'result' => $flows->resultId,
            'total' => FlowRowStore::count($flows->resultId),
            'itemSize' => $flows->itemSize > 0 ? $flows->itemSize : FlowRows::ITEM_SIZE,
            'columns' => $columns,
            'shown' => $shown,
            'template' => FlowRowStore::template($flows->resultId, $shown),
            'sortKey' => $flows->sortKey,
            'sortDir' => $flows->sortDir,
            'firstRows' => $flows->firstRows,
            'windowUrl' => $c->getAction('flows-window')?->url() ?? '',
            'sortUrl' => $c->getAction('flows-sort')?->url() ?? '',
            'ipInfoUrl' => $c->getAction('ip-info')?->url() ?? '',
        ]);
    }

    /**
     * Rows of the tab's list in its order, zone and columns.
     *
     * @return null|array{html: string, blocks: int}
     */
    private static function rows(FlowsState $flows, int $offset, int $count): ?array {
        $order = $flows->currentOrder();

        return $order === null ? null : FlowRowStore::window($flows->resultId, $offset, $count, $order, $flows->zone(), $flows->shownKeys());
    }

    /** Queues the host with the rows from $offset, its header and columns; false once the rows are gone. */
    private static function answer(Context $c, FlowsState $flows, int $offset, int $count): bool {
        $result = $flows->resultId;
        $columns = FlowRowStore::columns($result);
        $total = FlowRowStore::count($result);
        $first = min($offset, $total);
        $window = self::rows($flows, $first, min($count, $total - $first));
        if ($columns === null || $window === null) {
            return false;
        }
        $shown = $flows->shownKeys();
        $html = FlowRows::window(
            $result,
            $first,
            $total,
            FlowRowStore::template($result, $shown),
            FlowRows::header($columns, $shown, $flows->sortKey, $flows->sortDir),
            $window['html'],
        );
        $c->getPatchManager()->queuePatch(['type' => 'elements', 'content' => $html]);

        return true;
    }

    /** The result id the request names, when it is the tab's list result. */
    private static function current(Context $c, FlowsState $flows): ?string {
        $result = $c->input('result');

        return \is_string($result) && $result !== '' && $result === $flows->resultId && $flows->mode === 'list' ? $result : null;
    }

    /** The store dropped the rows: the page shows the notice instead of the list. */
    private static function lost(Context $c, FlowsState $flows): void {
        $flows->rowsLost = true;
        $c->sync();
    }
}
