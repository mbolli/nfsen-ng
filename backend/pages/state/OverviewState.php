<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

/** Per-tab results of the Overview page. */
final class OverviewState extends PageState {
    public function snapshot(): array {
        return $this->notifications === [] ? [] : ['notifications' => $this->notifications];
    }

    public function restore(array $data): void {
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
    }

    public function isEmpty(): bool {
        return $this->notifications === [];
    }
}
