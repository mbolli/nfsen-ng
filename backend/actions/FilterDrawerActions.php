<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\pages\FilterDrawer;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\DuplicateFilterException;
use mbolli\nfsen_ng\store\SavedFilterRepository;
use mbolli\nfsen_ng\store\SavedFilterSeeder;
use mbolli\nfsen_ng\store\StoreUnavailableException;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * The filter drawer's actions (4.5): open, and the saved filters. Outcomes go to the drawer's
 * status line (`_drawer_notice`). Closures catch \Throwable so the drawer shows the failure:
 * php-via would only log it and answer 500.
 */
final class FilterDrawerActions {
    public static function register(Context $c, Via $app): void {
        $actions = [
            'drawer-open' => self::open(...),
            // Posts drawer_open = false, so broadcast renders stop carrying the list and the grammar.
            'drawer-close' => static function (Context $c): void {},
            'filter-save' => self::save(...),
            'filter-update' => self::update(...),
            'filter-delete' => self::delete(...),
            'filter-star' => self::star(...),
            'filter-use' => self::use(...),
            'filter-migrate-local' => self::migrateLocal(...),
        ];
        foreach ($actions as $name => $handler) {
            $c->action(static function (Context $c) use ($app, $handler, $name): void {
                try {
                    $handler($c);
                } catch (\Throwable $e) {
                    // A duplicate or a bad name is the user's to fix, not the log's.
                    if (!$e instanceof \InvalidArgumentException && !$e instanceof DuplicateFilterException) {
                        Debug::getInstance()->log("{$name} failed: " . $e->getMessage(), LOG_ERR);
                    }
                    self::notice($c, $e instanceof DuplicateFilterException ? 'warning' : 'error', self::failure($e, $name));
                }
                self::push($c, $app);
            }, $name);
        }
    }

    /** `drawer-open`: validates the text the drawer opened with; the sync renders the editor and the list. */
    public static function open(Context $c, ?string $binary = null): void {
        if (FilterDrawer::target($c) === '') {
            $c->getSignal('drawer_target')?->setValue('');
        }
        self::notice($c, '', '');
        self::check($c, $binary);
    }

    /** `filter-save`: the editor's text under `drawer_name`, or under the text itself when unnamed. */
    public static function save(Context $c): void {
        $repository = self::repository();
        $expression = self::text($c, 'drawer_filter');
        $id = $repository->create(self::text($c, 'drawer_name'), $expression);
        $name = $repository->find($id)['name'] ?? $expression;
        self::reset($c);
        self::notice($c, 'success', "Saved as {$name}");
    }

    /**
     * `filter-update?id=`: name and expression from the editor; with `&rename=1` only the
     * name, keeping the expression.
     */
    public static function update(Context $c): void {
        $id = self::inputId($c);
        $repository = self::repository();
        $rename = $c->input('rename') === '1';
        $name = self::text($c, 'drawer_name');
        if ($rename) {
            $repository->rename($id, $name);
        } else {
            $repository->update($id, $name, self::text($c, 'drawer_filter'));
        }
        $saved = $repository->find($id)['name'] ?? $name;
        self::reset($c);
        self::notice($c, 'success', $rename ? "Renamed to {$saved}" : "Saved changes to {$saved}");
    }

    /** `filter-delete?id=`. */
    public static function delete(Context $c): void {
        $id = self::inputId($c);
        $repository = self::repository();
        $filter = $repository->find($id);
        $repository->delete($id);
        if (\in_array($id, [$c->getSignal('drawer_edit')?->int(), $c->getSignal('drawer_rename')?->int()], true)) {
            self::reset($c);
        }
        $filter === null
            ? self::notice($c, '', 'The saved filter no longer exists.')
            : self::notice($c, 'success', "Deleted {$filter['name']}");
    }

    /** `filter-star?id=&on=0|1`. */
    public static function star(Context $c): void {
        self::repository()->star(self::inputId($c), $c->input('on') === '1');
    }

    /** `filter-use?id=`: puts the expression into the editor and marks the filter used. */
    public static function use(Context $c, ?string $binary = null): void {
        $id = self::inputId($c);
        $repository = self::repository();
        $filter = $repository->find($id) ?? throw new \InvalidArgumentException('The saved filter no longer exists.');
        $repository->touch($id, time());
        $c->getSignal('drawer_filter')?->setValue($filter['expression']);
        self::reset($c);
        self::notice($c, '', "Loaded {$filter['name']} into the editor");
        self::check($c, $binary);
    }

    /**
     * `filter-migrate-local`: the browser's old saved list (3.8.2) in `drawer_import`. Only an
     * import that ran sets `_drawer_imported`; a failed or lost post is retried on the next load.
     */
    public static function migrateLocal(Context $c): void {
        $payload = self::text($c, 'drawer_import');

        try {
            if (trim($payload) === '') {
                return;
            }
            $items = self::browserItems($payload);
            if ($items !== []) {
                $db = Database::shared();
                $repository = new SavedFilterRepository($db);
                // Seeds the presets first, so a deleted deployment preset is known and stays deleted (D16).
                $repository->list();
                $seen = SavedFilterSeeder::seenDeploymentKeys($db);
                $items = array_values(array_filter(
                    $items,
                    static fn (array $item): bool => !\in_array(SavedFilterRepository::key($item['expression']), $seen, true),
                ));
                $count = $repository->import($items, 'browser');
                if ($count > 0) {
                    self::notice($c, 'success', 'Imported ' . $count . ' saved filter' . ($count === 1 ? '' : 's') . ' from this browser');
                }
            }
            $c->getSignal('_drawer_imported')?->setValue(bin2hex(random_bytes(8)));
        } finally {
            $c->getSignal('drawer_import')?->setValue('');
        }
    }

    /**
     * The old `localStorage['stored_filters']` list as import items: trimmed, empties skipped,
     * named after the expression. Anything that is not a JSON list gives none.
     *
     * @return list<array{name: string, expression: string}>
     */
    public static function browserItems(string $json): array {
        try {
            $list = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($list) || !array_is_list($list)) {
            return [];
        }

        $items = [];
        foreach ($list as $entry) {
            $expression = \is_string($entry) ? trim($entry) : '';
            if ($expression !== '') {
                $items[] = ['name' => SavedFilterRepository::defaultName($expression), 'expression' => $expression];
            }
        }

        return $items;
    }

    /** `_flt_drawer` already reads "Filter could not be checked", so the saved-filter line stays as it is. */
    private static function check(Context $c, ?string $binary): void {
        try {
            QueryKitActions::validate($c, 'drawer', $binary);
        } catch (\Throwable $e) {
            Debug::getInstance()->log('Could not check the filter: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /** @throws StoreUnavailableException */
    private static function repository(): SavedFilterRepository {
        return new SavedFilterRepository(Database::shared());
    }

    /** Leaves edit and rename mode and empties the name field. */
    private static function reset(Context $c): void {
        $c->getSignal('drawer_edit')?->setValue(0);
        $c->getSignal('drawer_rename')?->setValue(0);
        $c->getSignal('drawer_name')?->setValue('');
    }

    /** @param ''|'error'|'success'|'warning' $level */
    private static function notice(Context $c, string $level, string $text): void {
        $c->getSignal('_drawer_notice')?->setValue(
            ['id' => $text === '' ? '' : bin2hex(random_bytes(4)), 'level' => $level, 'text' => $text]
        );
    }

    /** The status line's text for a failed action; the repository's messages are for users. */
    private static function failure(\Throwable $e, string $action): string {
        if ($action === 'filter-migrate-local') {
            return "Could not import this browser's saved filters: " . ($e instanceof StoreUnavailableException ? $e->reason : $e->getMessage());
        }

        return match (true) {
            $e instanceof DuplicateFilterException, $e instanceof \InvalidArgumentException => $e->getMessage(),
            $e instanceof StoreUnavailableException => 'Saved filters unavailable: ' . $e->reason,
            default => 'The saved filters could not be changed: ' . $e->getMessage(),
        };
    }

    /** @throws \InvalidArgumentException for a missing or malformed `?id=` */
    private static function inputId(Context $c): int {
        $id = $c->input('id');
        $id = \is_int($id) ? (string) $id : $id;
        if (!\is_string($id) || preg_match('/^[1-9]\d{0,17}$/', $id) !== 1) {
            throw new \InvalidArgumentException('No saved filter was named.');
        }

        return (int) $id;
    }

    /** A client-posted value that is not a string reads as ''. */
    private static function text(Context $c, string $name): string {
        $value = $c->getSignal($name)?->getValue();

        return \is_string($value) ? $value : '';
    }

    /**
     * An open drawer re-renders its list; a closed one needs only its signals. Before the tab's
     * SSE stream is up the changed signals go out with the connect sync instead.
     */
    private static function push(Context $c, Via $app): void {
        if (!$c->isConnected()) {
            return;
        }
        $c->getSignal('drawer_open')?->bool() ? $c->sync() : $c->syncSignals();
    }
}
