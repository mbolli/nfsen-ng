<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Mutable runtime state for a single alert rule.
 * Persisted to alerts-state.json between evaluations; never stored in preferences.json.
 */
final class AlertState {
    public function __construct(
        public int $cooldownRemaining,
        public int $lastTriggeredAt,
        /** @var int[] Unix timestamps of the last ≤10 trigger events */
        public array $recentTriggers,
    ) {}

    public static function initial(): self {
        return new self(
            cooldownRemaining: 0,
            lastTriggeredAt: 0,
            recentTriggers: [],
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        return new self(
            cooldownRemaining: max(0, (int) ($data['cooldownRemaining'] ?? 0)),
            lastTriggeredAt: (int) ($data['lastTriggeredAt'] ?? 0),
            recentTriggers: array_values(array_map('intval', (array) ($data['recentTriggers'] ?? []))),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'cooldownRemaining' => $this->cooldownRemaining,
            'lastTriggeredAt' => $this->lastTriggeredAt,
            'recentTriggers' => $this->recentTriggers,
        ];
    }
}
