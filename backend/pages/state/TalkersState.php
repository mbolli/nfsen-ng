<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

/** Per-tab results of the Top Talkers page: the statistics table of the last run. */
final class TalkersState extends PageState {
    public string $tableHtml = '';

    /** Changes with every stored result (D26). */
    public string $resultId = '';

    public function setResult(string $tableHtml): void {
        $this->tableHtml = $tableHtml;
        $this->resultId = self::newResultId();
    }

    public function clearResult(): void {
        $this->setResult('');
    }

    public function snapshot(): array {
        if ($this->tableHtml === '') {
            return [];
        }

        return ['tableHtml' => $this->tableHtml, 'resultId' => $this->resultId, 'notifications' => $this->notifications];
    }

    public function restore(array $data): void {
        $this->tableHtml = self::stringFrom($data['tableHtml'] ?? '');
        $this->resultId = self::stringFrom($data['resultId'] ?? '');
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
    }

    public function isEmpty(): bool {
        return $this->tableHtml === '';
    }
}
