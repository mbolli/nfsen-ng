<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

use Mbolli\PhpVia\Context;

/**
 * Per-tab results of the Conversations page: the payload of the last run (4.4.3), its IP pairs
 * table and what the results card says about it.
 *
 * @phpstan-type Info array{pairs: int, share: ?float, metric: string, groupBy: string, direction: string, topN: int,
 *                          approximate: bool, command: string, start: int, end: int, live: bool, at: int,
 *                          fingerprint: string, others: null|array{bytes: int, packets: int, flows: int, share: float}}
 */
final class ConversationsState extends PageState {
    /** What a result without a stored Info reads as. */
    public const array EMPTY_INFO = [
        'pairs' => 0,
        'share' => null,
        'metric' => 'bytes',
        'groupBy' => 'ip',
        'direction' => 'forward',
        'topN' => 0,
        'approximate' => false,
        'command' => '',
        'start' => 0,
        'end' => 0,
        'live' => false,
        'at' => 0,
        'fingerprint' => '',
        'others' => null,
    ];

    /** JSON of ConversationPayload::build(); '' until a run stored one. */
    public string $payload = '';

    /** The IP pairs table (Table::generate()); '' when the run returned no pairs. */
    public string $tableHtml = '';

    /** @var Info */
    public array $info = self::EMPTY_INFO;

    /** Changes with every stored result (D26). */
    public string $resultId = '';

    /** @param array<string, mixed> $info merged over EMPTY_INFO */
    public function setResult(string $payload, string $tableHtml = '', array $info = []): void {
        $this->payload = $payload;
        $this->tableHtml = $tableHtml;
        $this->info = self::infoFrom($info);
        $this->resultId = self::newResultId();
    }

    public function clearResult(): void {
        $this->payload = '';
        $this->tableHtml = '';
        $this->info = self::EMPTY_INFO;
        $this->resultId = self::newResultId();
    }

    public function restoreSignals(Context $c): void {
        $c->getSignal('_conv_pairs')?->setValue($this->info['pairs'], broadcast: false);
    }

    public function snapshot(): array {
        if ($this->payload === '') {
            return [];
        }

        return [
            'payload' => $this->payload,
            'tableHtml' => $this->tableHtml,
            'info' => $this->info,
            'resultId' => $this->resultId,
            'notifications' => $this->notifications,
        ];
    }

    public function restore(array $data): void {
        $this->payload = self::stringFrom($data['payload'] ?? '');
        $this->tableHtml = self::stringFrom($data['tableHtml'] ?? '');
        $this->info = self::infoFrom(\is_array($data['info'] ?? null) ? $data['info'] : []);
        $this->resultId = self::stringFrom($data['resultId'] ?? '');
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
    }

    public function isEmpty(): bool {
        return $this->payload === '';
    }

    /**
     * Snapshot data comes back from app-global state, so every field is re-validated.
     *
     * @param array<mixed> $info
     *
     * @return Info
     */
    private static function infoFrom(array $info): array {
        $int = static fn (string $key, int $default = 0): int => \is_int($info[$key] ?? null) ? $info[$key] : $default;
        $string = static fn (string $key, string $default = ''): string => \is_string($info[$key] ?? null) ? $info[$key] : $default;
        $bool = static fn (string $key): bool => ($info[$key] ?? false) === true;
        $number = static fn (mixed $value): ?float => \is_float($value) || \is_int($value) ? (float) $value : null;
        $others = $info['others'] ?? null;

        return [
            'pairs' => $int('pairs'),
            'share' => $number($info['share'] ?? null),
            'metric' => $string('metric', 'bytes'),
            'groupBy' => $string('groupBy', 'ip'),
            'direction' => $string('direction', 'forward'),
            'topN' => $int('topN'),
            'approximate' => $bool('approximate'),
            'command' => $string('command'),
            'start' => $int('start'),
            'end' => $int('end'),
            'live' => $bool('live'),
            'at' => $int('at'),
            'fingerprint' => $string('fingerprint'),
            'others' => \is_array($others) ? [
                'bytes' => \is_int($others['bytes'] ?? null) ? $others['bytes'] : 0,
                'packets' => \is_int($others['packets'] ?? null) ? $others['packets'] : 0,
                'flows' => \is_int($others['flows'] ?? null) ? $others['flows'] : 0,
                'share' => $number($others['share'] ?? null) ?? 0.0,
            ] : null,
        ];
    }
}
