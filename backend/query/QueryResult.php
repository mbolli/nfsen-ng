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
     * @param mixed                      $rawOutput    nfdump's untouched output, for the table's
     *                                                 "original data" view
     * @param list<string>               $notes        what nfdump printed beside the data: limit and
     *                                                 error lines, "No matching flows", the
     *                                                 execution time
     * @param int                        $exitCode     nfdump's exit code; non-zero with rows means
     *                                                 the rows may be incomplete
     * @param int                        $parts        nfdump processes whose results were merged
     *                                                 into the rows, one per time slice
     * @param list<string>               $partCommands the command of each of those processes;
     *                                                 `command` is the one run they stand for
     */
    public function __construct(
        public array $rows,
        public string $command,
        public string $stderr,
        public float $elapsed,
        public TimeWindow $window,
        public mixed $rawOutput = null,
        public array $notes = [],
        public int $exitCode = 0,
        public int $parts = 1,
        public array $partCommands = [],
    ) {}

    public function isEmpty(): bool {
        return $this->rows === [];
    }

    public function count(): int {
        return \count($this->rows);
    }
}
