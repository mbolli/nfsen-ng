<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Immutable DTO representing one alert rule, stored in preferences.json.
 */
final class AlertRule {
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly string $profile,
        /** @var string[] */
        public readonly array $sources,
        /** flows|packets|bytes */
        public readonly string $metric,
        /** >|<|>=|<= */
        public readonly string $operator,
        /** absolute|percent_of_avg */
        public readonly string $thresholdType,
        public readonly float $thresholdValue,
        /** 10m|30m|1h|6h|12h|24h — only for percent_of_avg */
        public readonly string $avgWindow,
        /** Number of 5-min slots to suppress re-triggering after a fire */
        public readonly int $cooldownSlots,
        public readonly ?string $notifyEmail,
        public readonly ?string $notifyWebhook,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            enabled: (bool) ($data['enabled'] ?? true),
            profile: (string) ($data['profile'] ?? 'live'),
            sources: array_values(array_map('strval', (array) ($data['sources'] ?? []))),
            metric: (string) ($data['metric'] ?? 'bytes'),
            operator: (string) ($data['operator'] ?? '>'),
            thresholdType: (string) ($data['thresholdType'] ?? 'absolute'),
            thresholdValue: (float) ($data['thresholdValue'] ?? 0),
            avgWindow: (string) ($data['avgWindow'] ?? '1h'),
            cooldownSlots: max(0, (int) ($data['cooldownSlots'] ?? 3)),
            notifyEmail: isset($data['notifyEmail']) && $data['notifyEmail'] !== '' ? (string) $data['notifyEmail'] : null,
            notifyWebhook: isset($data['notifyWebhook']) && $data['notifyWebhook'] !== '' ? (string) $data['notifyWebhook'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'profile' => $this->profile,
            'sources' => $this->sources,
            'metric' => $this->metric,
            'operator' => $this->operator,
            'thresholdType' => $this->thresholdType,
            'thresholdValue' => $this->thresholdValue,
            'avgWindow' => $this->avgWindow,
            'cooldownSlots' => $this->cooldownSlots,
            'notifyEmail' => $this->notifyEmail,
            'notifyWebhook' => $this->notifyWebhook,
        ];
    }

    public function withEnabled(bool $enabled): self {
        return new self(
            id: $this->id,
            name: $this->name,
            enabled: $enabled,
            profile: $this->profile,
            sources: $this->sources,
            metric: $this->metric,
            operator: $this->operator,
            thresholdType: $this->thresholdType,
            thresholdValue: $this->thresholdValue,
            avgWindow: $this->avgWindow,
            cooldownSlots: $this->cooldownSlots,
            notifyEmail: $this->notifyEmail,
            notifyWebhook: $this->notifyWebhook,
        );
    }
}
