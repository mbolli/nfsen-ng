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
        /** Optional nfdump filter expression, e.g. "proto icmp" or "net 192.168.1.0/24" */
        public readonly ?string $nfdumpFilter = null,
        /** Optional per-rule override; null = fall through to the global default, then the built-in default */
        public readonly ?string $emailSubjectTemplate = null,
        public readonly ?string $emailBodyTemplate = null,
        public readonly ?string $webhookTitleTemplate = null,
        public readonly ?string $webhookMessageTemplate = null,
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
            nfdumpFilter: isset($data['nfdumpFilter']) && $data['nfdumpFilter'] !== '' ? (string) $data['nfdumpFilter'] : null,
            emailSubjectTemplate: isset($data['emailSubjectTemplate']) && $data['emailSubjectTemplate'] !== '' ? (string) $data['emailSubjectTemplate'] : null,
            emailBodyTemplate: isset($data['emailBodyTemplate']) && $data['emailBodyTemplate'] !== '' ? (string) $data['emailBodyTemplate'] : null,
            webhookTitleTemplate: isset($data['webhookTitleTemplate']) && $data['webhookTitleTemplate'] !== '' ? (string) $data['webhookTitleTemplate'] : null,
            webhookMessageTemplate: isset($data['webhookMessageTemplate']) && $data['webhookMessageTemplate'] !== '' ? (string) $data['webhookMessageTemplate'] : null,
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
            'nfdumpFilter' => $this->nfdumpFilter,
            'emailSubjectTemplate' => $this->emailSubjectTemplate,
            'emailBodyTemplate' => $this->emailBodyTemplate,
            'webhookTitleTemplate' => $this->webhookTitleTemplate,
            'webhookMessageTemplate' => $this->webhookMessageTemplate,
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
            nfdumpFilter: $this->nfdumpFilter,
            emailSubjectTemplate: $this->emailSubjectTemplate,
            emailBodyTemplate: $this->emailBodyTemplate,
            webhookTitleTemplate: $this->webhookTitleTemplate,
            webhookMessageTemplate: $this->webhookMessageTemplate,
        );
    }
}
