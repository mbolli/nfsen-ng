<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * What a capture-reading query returned, without deciding how to show it.
 *
 * The command and stderr travel with the rows deliberately: the UI renders them as a
 * notification, and an API caller needs them to audit what actually ran.
 */
final readonly class QueryResult {
    /**
     * @param list<array<string, mixed>> $rows
     * @param mixed                      $rawOutput nfdump's untouched output, for the table's
     *                                              "original data" view
     */
    public function __construct(
        public array $rows,
        public string $command,
        public string $stderr,
        public float $elapsed,
        public TimeWindow $window,
        public mixed $rawOutput = null,
    ) {}

    public function isEmpty(): bool {
        return $this->rows === [];
    }

    public function count(): int {
        return \count($this->rows);
    }
}
