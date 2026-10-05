<?php

declare(strict_types=1);

namespace Tests\Support;

use mbolli\nfsen_ng\processor\Processor;

/**
 * Records the nfdump invocations a unit under test would have made and replays
 * canned decoded rows and raw output, so the nfdump binary is never needed.
 *
 * State is static because callers construct a fresh processor per invocation
 * (`new Config::$processorClass()`), so per-instance recording would be lost.
 */
final class FakeProcessor implements Processor {
    /** @var list<array{options: array<string, mixed>, filter: string, profile: string, handle: string}> */
    public static array $calls = [];

    /** @var list<array<array<string, mixed>>> Consumed one per execute() call, in order. */
    public static array $responses = [];

    /** @var null|array<array<string, mixed>> Returned once the queue above is exhausted. */
    public static ?array $defaultResponse = null;

    /** @var list<string> rawOutput per execute() call, in order; '' once exhausted. */
    public static array $rawResponses = [];

    /** When set, every execute() throws it, to model an nfdump failure. */
    public static ?\Exception $throw = null;

    /** @var array<string, mixed> */
    private array $options = [];
    private string $filter = '';
    private string $profile = '';
    private string $handle = '';

    public static function reset(): void {
        self::$calls = [];
        self::$responses = [];
        self::$defaultResponse = null;
        self::$rawResponses = [];
        self::$throw = null;
    }

    /** Queues the rawOutput of the next execute() call not yet answered. */
    public static function queueRaw(string $raw): void {
        self::$rawResponses[] = $raw;
    }

    /** Options of the nth recorded call. */
    public static function callOptions(int $index = 0): array {
        return self::$calls[$index]['options'] ?? [];
    }

    public function setOption(string $option, $value): void {
        $this->options[$option] = $value;
    }

    public function setFilter(string $filter): void {
        $this->filter = $filter;
    }

    public function setProfile(string $profile): void {
        $this->profile = $profile;
    }

    public function setQueryHandle(string $handle): void {
        $this->handle = $handle;
    }

    public function execute(): array {
        self::$calls[] = [
            'options' => $this->options,
            'filter' => $this->filter,
            'profile' => $this->profile,
            'handle' => $this->handle,
        ];

        if (self::$throw !== null) {
            throw self::$throw;
        }

        $decoded = array_shift(self::$responses) ?? self::$defaultResponse ?? [];
        $raw = array_shift(self::$rawResponses) ?? '';

        return ['command' => 'fake-nfdump', 'rawOutput' => $raw, 'decoded' => $decoded, 'notes' => [], 'exitCode' => 0];
    }
}
