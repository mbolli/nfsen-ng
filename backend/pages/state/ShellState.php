<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

/**
 * Per-tab state of the shell: the modal the layout renders into #modal-root (D24), the
 * last alert toast this tab showed, and the import throttles of the traffic graph and the
 * data range.
 */
final class ShellState extends PageState {
    /** During an import the graph and the data range refresh at most every 10 s instead of per imported file. */
    public const int IMPORT_THROTTLE = 10;

    /** Trusted HTML rendered by the server (IP info, alert test result). */
    public string $modalHtml = '';

    /** ts of the newest alert_fired event this tab has shown a toast for. */
    public int $lastAlertShown = 0;

    /** When the traffic graph was last fetched for this tab. */
    public int $graphFetchedAt = 0;

    /** When the data range was last read for this tab; pages without the graph read it too. */
    public int $rangeFetchedAt = 0;

    /** GraphData JSON of the last fetch, reused while an import throttles the next one. */
    public string $graphJson = '[]';

    /** @var list<string> series keys of the last fetch */
    public array $graphLegend = [];

    /** Seconds per point of the last fetch; 0 when it returned no series. */
    public int $graphStep = 0;

    /** Also due after a newer data range read, which may have moved the live window. */
    public function graphDue(bool $importing, int $now): bool {
        return $this->rangeFetchedAt > $this->graphFetchedAt || self::due($this->graphFetchedAt, $importing, $now);
    }

    public function rangeDue(bool $importing, int $now): bool {
        return self::due($this->rangeFetchedAt, $importing, $now);
    }

    public function snapshot(): array {
        return $this->modalHtml === '' ? [] : ['modalHtml' => $this->modalHtml];
    }

    public function restore(array $data): void {
        $this->modalHtml = self::stringFrom($data['modalHtml'] ?? '');
    }

    public function isEmpty(): bool {
        return $this->modalHtml === '';
    }

    private static function due(int $fetchedAt, bool $importing, int $now): bool {
        return !$importing || $now - $fetchedAt >= self::IMPORT_THROTTLE;
    }
}
