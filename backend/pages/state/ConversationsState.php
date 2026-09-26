<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

/** Per-tab results of the Conversations page: the {nodes, links} payload of the last run. */
final class ConversationsState extends PageState {
    public const string EMPTY_PAYLOAD = '{"nodes":[],"links":[]}';

    /** JSON {nodes, links}; '' until a run stored one. */
    public string $sankeyData = '';

    /** Changes with every stored result (D26). */
    public string $resultId = '';

    public function setResult(string $sankeyData): void {
        $this->sankeyData = $sankeyData;
        $this->resultId = self::newResultId();
    }

    /** The payload the chart renders: an empty graph until a run stored one. */
    public function payload(): string {
        return $this->sankeyData !== '' ? $this->sankeyData : self::EMPTY_PAYLOAD;
    }

    public function snapshot(): array {
        if ($this->sankeyData === '') {
            return [];
        }

        return ['sankeyData' => $this->sankeyData, 'resultId' => $this->resultId, 'notifications' => $this->notifications];
    }

    public function restore(array $data): void {
        $this->sankeyData = self::stringFrom($data['sankeyData'] ?? '');
        $this->resultId = self::stringFrom($data['resultId'] ?? '');
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
    }

    public function isEmpty(): bool {
        return $this->sankeyData === '';
    }
}
