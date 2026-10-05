<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

/**
 * Per-tab results of the Overview page: the precomputed top-N behind the KPI strip and the
 * top-N card (written by overview-topn), and the exact nfdump run out of retention.
 *
 * @phpstan-type TopNRow array{key: string, source: string, flows: int, packets: int, bytes: int, share: float, intervals: int}
 * @phpstan-type TopN array{
 *     fingerprint: string,
 *     kpiFingerprint: string,
 *     computedAt: int,
 *     state: string,
 *     reason: string,
 *     kpi: array{src: null|TopNRow, dst: null|TopNRow, proto: null|TopNRow},
 *     table: array{tab: string, dir: string, rows: list<TopNRow>, exactIntervals: bool, expected: int, coverage: float},
 * }
 * @phpstan-type ExactRow array{key: string, flows: int, packets: int, bytes: int, share: null|float}
 * @phpstan-type Exact array{
 *     fingerprint: string,
 *     computedAt: int,
 *     live: bool,
 *     tab: string,
 *     dir: string,
 *     start: int,
 *     end: int,
 *     rows: list<ExactRow>,
 *     command: string,
 * }
 */
final class OverviewState extends PageState {
    /** @var null|TopN */
    public ?array $topn = null;

    /** @var null|Exact */
    public ?array $exact = null;

    /** Newest overview-topn request of this tab; an older one never writes its answer. */
    public int $topnTicket = 0;

    /** Fingerprint of the overview-topn computation in flight, '' when none. */
    public string $topnRunning = '';

    public function snapshot(): array {
        return array_filter([
            'notifications' => $this->notifications,
            'topn' => $this->topn,
            'exact' => $this->exact,
        ], static fn (mixed $value): bool => $value !== [] && $value !== null);
    }

    public function restore(array $data): void {
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
        $this->topn = self::topnFrom($data['topn'] ?? null);
        $this->exact = self::exactFrom($data['exact'] ?? null);
    }

    public function isEmpty(): bool {
        return $this->notifications === [] && $this->topn === null && $this->exact === null;
    }

    /**
     * A snapshot comes back from app-global state, so its shape is checked on the way in.
     *
     * @return null|TopN
     */
    public static function topnFrom(mixed $value): ?array {
        if (!\is_array($value) || !\is_array($value['kpi'] ?? null) || !\is_array($value['table'] ?? null)) {
            return null;
        }
        $table = $value['table'];
        $kpi = $value['kpi'];
        $rows = self::rowsFrom($table['rows'] ?? null);
        if ($rows === null) {
            return null;
        }

        return [
            'fingerprint' => self::stringFrom($value['fingerprint'] ?? ''),
            'kpiFingerprint' => self::stringFrom($value['kpiFingerprint'] ?? ''),
            'computedAt' => self::intFrom($value['computedAt'] ?? 0),
            'state' => self::stringFrom($value['state'] ?? ''),
            'reason' => self::stringFrom($value['reason'] ?? ''),
            'kpi' => [
                'src' => self::rowsFrom([$kpi['src'] ?? null])[0] ?? null,
                'dst' => self::rowsFrom([$kpi['dst'] ?? null])[0] ?? null,
                'proto' => self::rowsFrom([$kpi['proto'] ?? null])[0] ?? null,
            ],
            'table' => [
                'tab' => self::stringFrom($table['tab'] ?? ''),
                'dir' => self::stringFrom($table['dir'] ?? ''),
                'rows' => $rows,
                'exactIntervals' => ($table['exactIntervals'] ?? false) === true,
                'expected' => self::intFrom($table['expected'] ?? 0),
                'coverage' => self::floatFrom($table['coverage'] ?? 0.0),
            ],
        ];
    }

    /** @return null|Exact */
    public static function exactFrom(mixed $value): ?array {
        if (!\is_array($value) || !\is_array($value['rows'] ?? null)) {
            return null;
        }
        $rows = [];
        foreach ($value['rows'] as $row) {
            if (!\is_array($row) || !\is_string($row['key'] ?? null)) {
                return null;
            }
            $share = $row['share'] ?? null;
            $rows[] = [
                'key' => $row['key'],
                'flows' => self::intFrom($row['flows'] ?? 0),
                'packets' => self::intFrom($row['packets'] ?? 0),
                'bytes' => self::intFrom($row['bytes'] ?? 0),
                'share' => \is_int($share) || \is_float($share) ? (float) $share : null,
            ];
        }

        return [
            'fingerprint' => self::stringFrom($value['fingerprint'] ?? ''),
            'computedAt' => self::intFrom($value['computedAt'] ?? 0),
            'live' => ($value['live'] ?? false) === true,
            'tab' => self::stringFrom($value['tab'] ?? ''),
            'dir' => self::stringFrom($value['dir'] ?? ''),
            'start' => self::intFrom($value['start'] ?? 0),
            'end' => self::intFrom($value['end'] ?? 0),
            'rows' => $rows,
            'command' => self::stringFrom($value['command'] ?? ''),
        ];
    }

    /** @return null|list<TopNRow> null when any entry is malformed */
    private static function rowsFrom(mixed $value): ?array {
        if (!\is_array($value)) {
            return null;
        }
        $rows = [];
        foreach ($value as $row) {
            if (!\is_array($row) || !\is_string($row['key'] ?? null)) {
                return null;
            }
            $rows[] = [
                'key' => $row['key'],
                'source' => self::stringFrom($row['source'] ?? ''),
                'flows' => self::intFrom($row['flows'] ?? 0),
                'packets' => self::intFrom($row['packets'] ?? 0),
                'bytes' => self::intFrom($row['bytes'] ?? 0),
                'share' => self::floatFrom($row['share'] ?? 0.0),
                'intervals' => self::intFrom($row['intervals'] ?? 0),
            ];
        }

        return $rows;
    }

    private static function intFrom(mixed $value): int {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private static function floatFrom(mixed $value): float {
        return \is_int($value) || \is_float($value) ? (float) $value : 0.0;
    }
}
