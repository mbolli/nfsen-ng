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
        /** @var list<int> Unix timestamps of the last 10 or fewer trigger events */
        public array $recentTriggers,
        public bool $firing = false,
        /** Slot of the 'fired' event that started the current firing period */
        public ?int $firedAt = null,
        /** The rule's metric value in the last evaluated slot */
        public ?float $lastValue = null,
        /** Start of the last data interval evaluated (from the nfcapd file name) */
        public ?int $lastEvaluatedSlot = null,
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
            firing: (bool) ($data['firing'] ?? false),
            firedAt: isset($data['firedAt']) ? (int) $data['firedAt'] : null,
            lastValue: isset($data['lastValue']) ? (float) $data['lastValue'] : null,
            lastEvaluatedSlot: isset($data['lastEvaluatedSlot']) ? (int) $data['lastEvaluatedSlot'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'cooldownRemaining' => $this->cooldownRemaining,
            'lastTriggeredAt' => $this->lastTriggeredAt,
            'recentTriggers' => $this->recentTriggers,
            'firing' => $this->firing,
            'firedAt' => $this->firedAt,
            'lastValue' => $this->lastValue,
            'lastEvaluatedSlot' => $this->lastEvaluatedSlot,
        ];
    }
}
