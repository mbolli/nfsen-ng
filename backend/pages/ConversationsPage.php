<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\SankeyActions;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Conversations (was Sankey): who talks to whom in the range (4.4). */
final class ConversationsPage implements Page {
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
        // Optional middle column: src IP -> dst port -> dst IP.
        $c->signal(false, 'sankey_show_ports', clientWritable: true);
        // Byte thresholds, composed into the filter as bytes > / bytes <.
        $c->signal('', 'sankey_lower_limit', clientWritable: true);
        $c->signal('', 'sankey_upper_limit', clientWritable: true);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        SankeyActions::register($c, $states);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $conversations = $states->conversations;

        return [
            'notifications' => $conversations->notifications,
            'result' => [
                'id' => $conversations->resultId,
                'send' => $conversations->sendResult('sankey', $conversations->resultId, $isUpdate),
            ],
            Shell::LEGACY => [
                'sankeyData' => $conversations->payload(),
                'sankeyNotifications' => $conversations->notifications,
            ],
        ];
    }
}
