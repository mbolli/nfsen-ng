<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\pages\state\ConversationsState;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\pages\state\OverviewState;
use mbolli\nfsen_ng\pages\state\PageState;
use mbolli\nfsen_ng\pages\state\ShellState;
use mbolli\nfsen_ng\pages\state\TalkersState;

/**
 * Per-tab container for the plain-PHP results that actions hand to the view (1.4.2).
 * One instance per context, created by the page handler.
 */
final readonly class PageStates {
    public function __construct(
        public ShellState $shell = new ShellState(),
        public OverviewState $overview = new OverviewState(),
        public TalkersState $talkers = new TalkersState(),
        public FlowsState $flows = new FlowsState(),
        public ConversationsState $conversations = new ConversationsState(),
    ) {}

    /** The state of a page id, or 'shell'; null for pages that keep no results. */
    public function for(string $pageId): ?PageState {
        return $this->all()[$pageId] ?? null;
    }

    /** @return array<string, PageState> keyed by page id, plus 'shell' */
    public function all(): array {
        return [
            'shell' => $this->shell,
            OverviewPage::id() => $this->overview,
            TalkersPage::id() => $this->talkers,
            FlowsPage::id() => $this->flows,
            ConversationsPage::id() => $this->conversations,
        ];
    }
}
