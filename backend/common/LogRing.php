<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Fixed-size in-memory log for the Health page: the last N lines that passed the log level.
 *
 * @phpstan-type LogEntry array{seq: int, ts: int, level: int, levelName: string, message: string}
 */
final class LogRing {
    public const MAX_MESSAGE_LENGTH = 4000;

    /** @var array<int, array{seq: int, ts_ms: int, level: int, message: string}> keyed by seq % capacity */
    private array $slots = [];

    private int $lastSeq = 0;

    public function __construct(private readonly int $capacity = 200) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('LogRing capacity must be at least 1, got ' . $capacity);
        }
    }

    public function push(int $priority, string $message): void {
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 1) . '…';
        }

        $seq = ++$this->lastSeq;
        $this->slots[$seq % $this->capacity] = [
            'seq' => $seq,
            'ts_ms' => (int) floor(microtime(true) * 1000),
            'level' => max(LOG_EMERG, min(LOG_DEBUG, $priority)),
            'message' => $message,
        ];
    }

    /**
     * Newest first. `ts` is the epoch in milliseconds.
     *
     * @return list<LogEntry>
     */
    public function recent(int $limit = 200, int $maxPriority = LOG_DEBUG): array {
        $entries = [];
        for ($seq = $this->lastSeq; $seq > $this->oldestSeq() && \count($entries) < $limit; --$seq) {
            $slot = $this->slots[$seq % $this->capacity];
            if ($slot['level'] <= $maxPriority) {
                $entries[] = self::export($slot);
            }
        }

        return $entries;
    }

    /**
     * Entries newer than $seq, oldest first, for a client that already holds everything up to $seq.
     *
     * @return list<LogEntry>
     */
    public function since(int $seq): array {
        $entries = [];
        for ($next = max($seq, $this->oldestSeq()) + 1; $next <= $this->lastSeq; ++$next) {
            $entries[] = self::export($this->slots[$next % $this->capacity]);
        }

        return $entries;
    }

    public function lastSeq(): int {
        return $this->lastSeq;
    }

    /** The seq just before the oldest entry still held. */
    private function oldestSeq(): int {
        return max(0, $this->lastSeq - $this->capacity);
    }

    /**
     * @param array{seq: int, ts_ms: int, level: int, message: string} $slot
     *
     * @return LogEntry
     */
    private static function export(array $slot): array {
        return [
            'seq' => $slot['seq'],
            'ts' => $slot['ts_ms'],
            'level' => $slot['level'],
            'levelName' => Settings::logLevelToString($slot['level']),
            'message' => $slot['message'],
        ];
    }
}
