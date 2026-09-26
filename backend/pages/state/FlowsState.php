<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

use Mbolli\PhpVia\Context;

/** Per-tab results of the Flows page: the flow table of the last run and its row count. */
final class FlowsState extends PageState {
    public string $tableHtml = '';

    /** Rows the last run returned, mirrored into the flows_count signal. */
    public int $count = 0;

    /** Changes with every stored result (D26). */
    public string $resultId = '';

    public function setResult(string $tableHtml, int $count): void {
        $this->tableHtml = $tableHtml;
        $this->count = $count;
        $this->resultId = self::newResultId();
    }

    public function clearResult(): void {
        $this->setResult('', 0);
    }

    public function restoreSignals(Context $c): void {
        $c->getSignal('flows_count')?->setValue($this->count, broadcast: false);
    }

    public function snapshot(): array {
        if ($this->tableHtml === '') {
            return [];
        }

        return [
            'tableHtml' => $this->tableHtml,
            'count' => $this->count,
            'resultId' => $this->resultId,
            'notifications' => $this->notifications,
        ];
    }

    public function restore(array $data): void {
        $this->tableHtml = self::stringFrom($data['tableHtml'] ?? '');
        $this->count = \is_int($data['count'] ?? null) ? $data['count'] : 0;
        $this->resultId = self::stringFrom($data['resultId'] ?? '');
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
    }

    public function isEmpty(): bool {
        return $this->tableHtml === '';
    }
}
