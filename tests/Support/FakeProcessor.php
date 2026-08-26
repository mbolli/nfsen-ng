<?php

declare(strict_types=1);

namespace Tests\Support;

use mbolli\nfsen_ng\processor\Processor;

/**
 * Records the nfdump invocations a unit under test would have made and replays
 * canned decoded rows, so the nfdump binary is never needed.
 *
 * State is static because callers construct a fresh processor per invocation
 * (`new Config::$processorClass()`), so per-instance recording would be lost.
 */
final class FakeProcessor implements Processor {
    /** @var list<array{options: array<string, mixed>, filter: string, profile: string}> */
    public static array $calls = [];

    /** @var list<array<array<string, mixed>>> Consumed one per execute() call, in order. */
    public static array $responses = [];

    /** @var null|array<array<string, mixed>> Returned whenever is exhausted. */
    public static ?array $defaultResponse = null;

    /** When set, every execute() throws it — models an nfdump failure. */
    public static ?\Exception $throw = null;

    /** @var array<string, mixed> */
    private array $options = [];
    private string $filter = '';
    private string $profile = '';

    public static function reset(): void {
        self::$calls = [];
        self::$responses = [];
        self::$defaultResponse = null;
        self::$throw = null;
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

    public function execute(): array {
        self::$calls[] = [
            'options' => $this->options,
            'filter' => $this->filter,
            'profile' => $this->profile,
        ];

        if (self::$throw !== null) {
            throw self::$throw;
        }

        $decoded = array_shift(self::$responses) ?? self::$defaultResponse ?? [];

        return ['command' => 'fake-nfdump', 'rawOutput' => '', 'decoded' => $decoded];
    }
}
