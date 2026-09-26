<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\FilterDrawerActions;
use mbolli\nfsen_ng\query\FilterGrammar;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\SavedFilterRepository;
use mbolli\nfsen_ng\store\StoreUnavailableException;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Shell module for the filter builder drawer (4.5). A closed drawer renders only its frame;
 * the saved list and the grammar are read while `drawer_open` is true (1.7).
 *
 * @phpstan-import-type SavedFilter from SavedFilterRepository
 *
 * @phpstan-type DrawerTarget array{label: string, signal: string, runs: bool}
 */
final class FilterDrawer implements ShellModule {
    /** Targets the drawer edits, with the label its title names and whether the page has a Run. */
    public const array TARGETS = [
        'overview' => ['label' => 'Overview', 'runs' => true],
        'talkers' => ['label' => 'Top Talkers', 'runs' => true],
        'flows' => ['label' => 'Flows', 'runs' => true],
        'conversations' => ['label' => 'Conversations', 'runs' => true],
        'alert' => ['label' => 'Alert rule', 'runs' => false],
    ];

    /** @var array{id: string, level: ''|'error'|'success'|'warning', text: string} */
    public const array NOTICE_DEFAULT = ['id' => '', 'level' => '', 'text' => ''];

    public static function signals(Context $c): void {
        $c->signal('', 'drawer_target', clientWritable: true);
        $c->signal('', 'drawer_filter', clientWritable: true);
        $c->signal('', 'drawer_name', clientWritable: true);
        // Set only for the one filter-migrate-local post, which clears it again.
        $c->signal('', 'drawer_import', clientWritable: true);
        // A fresh id once that post has imported: the only thing that marks the browser done.
        $c->signal('', '_drawer_imported');
        $c->signal(false, 'drawer_open', clientWritable: true);
        // The saved filter whose expression or name the editor is changing, 0 for none.
        $c->signal(0, 'drawer_edit', clientWritable: true);
        $c->signal(0, 'drawer_rename', clientWritable: true);
        $c->signal(self::NOTICE_DEFAULT, '_drawer_notice');
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        FilterDrawerActions::register($c, $app);
    }

    /**
     * `targets` on every render (the filter fields show their Builder and Saved buttons from
     * it); the grammar and the saved list only while the drawer is open.
     *
     * @return array{open: bool, target: string, targets: array<string, DrawerTarget>, grammar: null|array{fields: list<array{group: string, label: string, snippet: string, help: string}>, examples: list<array{expression: string, description: string}>, keywords: list<string>}, savedFilters: list<SavedFilter>, savedError: string, noticeDefault: array<string, string>}
     */
    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        $open = $c->getSignal('drawer_open')?->bool() ?? false;
        $target = self::target($c);
        $saved = ['filters' => [], 'error' => ''];
        if ($open) {
            $saved = self::savedFilters();
        }

        return [
            'open' => $open,
            'target' => $target,
            'targets' => self::targets($c),
            'grammar' => $open ? [
                'fields' => FilterGrammar::fields(),
                'examples' => FilterGrammar::examples(),
                'keywords' => FilterGrammar::keywords(),
            ] : null,
            'savedFilters' => $saved['filters'],
            'savedError' => $saved['error'],
            'noticeDefault' => self::NOTICE_DEFAULT,
        ];
    }

    /**
     * Per target: its label, the wire id of the filter signal it edits and whether it runs.
     *
     * @return array<string, DrawerTarget>
     */
    public static function targets(Context $c): array {
        $targets = [];
        foreach (self::TARGETS as $target => $meta) {
            $signal = $c->getSignal(QueryKit::TARGETS[$target]['filter']);
            if ($signal === null) {
                continue;
            }
            $targets[$target] = ['label' => $meta['label'], 'signal' => $signal->id(), 'runs' => $meta['runs']];
        }

        return $targets;
    }

    /** `drawer_target` when it names a known target, else ''. */
    public static function target(Context $c): string {
        $target = $c->getSignal('drawer_target')?->string() ?? '';

        return \array_key_exists($target, self::TARGETS) ? $target : '';
    }

    /**
     * The saved list, or the reason the store cannot give it.
     *
     * @return array{filters: list<SavedFilter>, error: string}
     */
    private static function savedFilters(): array {
        try {
            return ['filters' => new SavedFilterRepository(Database::shared())->list(), 'error' => ''];
        } catch (StoreUnavailableException $e) {
            return ['filters' => [], 'error' => 'Saved filters unavailable: ' . $e->reason];
        } catch (\Throwable $e) {
            return ['filters' => [], 'error' => 'Saved filters unavailable: ' . $e->getMessage()];
        }
    }
}
