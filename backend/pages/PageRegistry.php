<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

/** Ordered pages and shell modules, and the id maps around them (1.4.2, D2). */
final class PageRegistry {
    /** Only the active page renders in full; the others render a skeleton (1.2). */
    public const bool LAZY = true;

    /** @var list<class-string<Page>> in sidebar order */
    public const array PAGES = [OverviewPage::class, TalkersPage::class, FlowsPage::class,
        ConversationsPage::class, AlertsPage::class, HealthPage::class, SettingsPage::class];

    /** @var list<class-string<ShellModule>> in render order: range before graph */
    public const array MODULES = [RangeControls::class, TrafficGraph::class, QueryKit::class, FilterDrawer::class];

    /** Old view ids that became a page of their own (D2). */
    private const array LEGACY = [
        'graphs' => 'overview',
        'statistics' => 'talkers',
        'sankey' => 'conversations',
        'investigate' => 'flows',
    ];

    /** Sections of the old Settings view that became a page (D2). */
    private const array LEGACY_SETTINGS = [
        'import' => 'health',
        'health' => 'health',
        'alerts' => 'alerts',
        'preferences' => 'settings',
        'system' => 'settings',
    ];

    /** query_kind values (1.6) and the page whose state receives their notices. */
    private const array KINDS = [
        'graph' => 'overview',
        'overview-topn' => 'overview',
        'stats' => 'talkers',
        'talkers-panel' => 'talkers',
        'flows' => 'flows',
        'flows-summary' => 'flows',
        'flowsgraph' => 'flows',
        'conversations' => 'conversations',
    ];

    /** The old view id of a page, which the old Settings form's Default view select still lists. */
    private const array TO_LEGACY = [
        'overview' => 'graphs',
        'talkers' => 'statistics',
        'flows' => 'flows',
        'conversations' => 'sankey',
        'alerts' => 'settings',
        'health' => 'settings',
        'settings' => 'settings',
    ];

    /** Whether only the active page renders in full; a method so callers do not branch on a literal. */
    public static function lazy(): bool {
        return self::LAZY;
    }

    /** @return list<string> */
    public static function ids(): array {
        return array_map(static fn (string $page): string => $page::id(), self::PAGES);
    }

    /** @return null|class-string<Page> */
    public static function find(string $id): ?string {
        foreach (self::PAGES as $page) {
            if ($page::id() === $id) {
                return $page;
            }
        }

        return null;
    }

    public static function isAnalysis(string $id): bool {
        $page = self::find($id);

        return $page !== null && $page::group() === 'analysis';
    }

    /** Whether a render with this page active runs the analysis parts: graph fetch and live window advance. */
    public static function rendersAnalysis(string $activePage): bool {
        return !self::lazy() || self::isAnalysis($activePage);
    }

    /** Maps a legacy view id or a stored preference value to a page id; '' when unknown. */
    public static function fromLegacy(string $view, string $section = ''): string {
        $view = strtolower(trim($view));
        if ($view === 'settings') {
            return self::LEGACY_SETTINGS[strtolower(trim($section))] ?? 'settings';
        }
        if (self::find($view) !== null) {
            return $view;
        }

        return self::LEGACY[$view] ?? '';
    }

    /** The old view id for a page id (or a legacy id), 'graphs' when unknown. */
    public static function toLegacy(string $pageId): string {
        return self::TO_LEGACY[self::fromLegacy($pageId)] ?? 'graphs';
    }

    /** Page id that owns a query_kind (1.6), used by kill-nfdump to route its notice. */
    public static function pageForKind(string $kind): ?string {
        return self::KINDS[$kind] ?? null;
    }

    /** @return list<array{id: string, title: string, lede: string, icon: string, group: string, analysis: bool}> */
    public static function meta(): array {
        return array_map(static fn (string $page): array => [
            'id' => $page::id(),
            'title' => $page::title(),
            'lede' => $page::lede(),
            'icon' => $page::icon(),
            'group' => $page::group(),
            'analysis' => $page::group() === 'analysis',
        ], self::PAGES);
    }
}
