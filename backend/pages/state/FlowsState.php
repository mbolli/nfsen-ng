<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

use mbolli\nfsen_ng\query\FlowsQuery;
use Mbolli\PhpVia\Context;

/**
 * Per-tab results of the Flows page (4.3.3). The table's first page stays here; the other rows
 * and nfdump's output wait compressed in a store every tab shares, under one byte budget.
 *
 * @phpstan-import-type ReturnedSummary from FlowsQuery
 * @phpstan-import-type FilteredTotals from FlowsQuery
 *
 * @phpstan-type Totals array{flows: float, packets: float, bytes: float}
 * @phpstan-type RangeSummary array{available: bool, reason: string, totals: array<string, Totals>}
 * @phpstan-type FilteredSummary array{totals: FilteredTotals, command: string, elapsed: float, fingerprint: string, ranAt: int}
 * @phpstan-type Run array{resultId?: string, limit?: int, command?: string, notes?: list<string>, rowChunks?: iterable<string>,
 *                        rawOutput?: string, rawBytes?: int, elapsed?: float, returnedSummary?: ?ReturnedSummary,
 *                        rangeSummary?: ?RangeSummary, rangePending?: bool, fingerprint?: string, totalsFingerprint?: string,
 *                        ranAt?: int, start?: int, end?: int, live?: bool}
 */
final class FlowsState extends PageState {
    /** The page keeps at most this much of nfdump's stdout (4.3.2). */
    public const int RAW_OUTPUT_LIMIT = 5 * 1024 * 1024;

    /** The kept output goes out in pieces of about this size, one event each. */
    public const int RAW_CHUNK_BYTES = 512 * 1024;

    /** Compressed rows and output that all tabs of a worker keep together. */
    public const int PAYLOAD_BUDGET = 12 * 1024 * 1024;

    /** The table as a render sends it: its first page, the rest is in the store. */
    public string $tableHtml = '';

    /** Rows the last run returned, mirrored into the flows_count signal. */
    public int $count = 0;

    /** Changes with every stored result (D26). */
    public string $resultId = '';

    public int $limit = 0;

    public string $command = '';

    /** @var list<string> */
    public array $notes = [];

    /** The size of nfdump's stdout, and how much of it the page keeps. */
    public int $rawBytes = 0;

    public int $rawKept = 0;

    /** Chunks of the rows past the first page, and of the kept output. */
    public int $rowChunks = 0;

    public int $rawChunks = 0;

    /** Set once the store dropped chunks the client still needed. */
    public bool $rowsLost = false;

    public bool $rawLost = false;

    public float $elapsed = 0.0;

    /** @var null|ReturnedSummary */
    public ?array $returnedSummary = null;

    /** @var null|RangeSummary */
    public ?array $rangeSummary = null;

    /** The range totals are read after the table went out. */
    public bool $rangePending = false;

    /** @var null|FilteredSummary */
    public ?array $filteredSummary = null;

    /** The query as run (1.7): a live window is encoded by its width, so it does not age into "stale". */
    public string $fingerprint = '';

    /** The same without the row limit, ordering and aggregation, which the totals ignore. */
    public string $totalsFingerprint = '';

    public int $ranAt = 0;

    public int $windowStart = 0;

    public int $windowEnd = 0;

    public bool $live = false;

    /**
     * result id => its chunk counts, lengths and gzcompress()ed chunks in one string, least recently
     * used first. One allocation per result pins fewer heap chunks than one per chunk.
     *
     * @var array<string, string>
     */
    private static array $payloads = [];

    private static int $payloadBytes = 0;

    /** tableHtml compressed once, for the revival snapshot. */
    private string $tableGz = '';

    /** @param Run $run */
    public function setResult(string $tableHtml, int $count, array $run = []): void {
        self::forget($this->resultId);
        $this->resultId = $run['resultId'] ?? self::newResultId();
        $this->tableHtml = $tableHtml;
        $this->tableGz = '';
        $this->count = $count;
        $this->limit = $run['limit'] ?? 0;
        $this->command = $run['command'] ?? '';
        $this->notes = $run['notes'] ?? [];
        $this->elapsed = $run['elapsed'] ?? 0.0;
        $this->returnedSummary = $run['returnedSummary'] ?? null;
        $this->rangeSummary = $run['rangeSummary'] ?? null;
        $this->rangePending = $run['rangePending'] ?? false;
        $this->filteredSummary = null;
        $this->fingerprint = $run['fingerprint'] ?? '';
        $this->totalsFingerprint = $run['totalsFingerprint'] ?? '';
        $this->ranAt = $run['ranAt'] ?? 0;
        $this->windowStart = $run['start'] ?? 0;
        $this->windowEnd = $run['end'] ?? 0;
        $this->live = $run['live'] ?? false;
        $this->rowsLost = false;
        $this->rawLost = false;

        $rows = [];
        foreach ($run['rowChunks'] ?? [] as $html) {
            $rows[] = self::compress($html);
        }
        $raw = $run['rawOutput'] ?? '';
        $this->rawBytes = max($run['rawBytes'] ?? 0, \strlen($raw));
        [$pieces, $this->rawKept] = self::rawPieces($raw);
        $this->rowChunks = \count($rows);
        $this->rawChunks = \count($pieces);
        if ($rows !== [] || $pieces !== []) {
            self::keep($this->resultId, $rows, $pieces);
        }
    }

    public function clearResult(): void {
        $this->setResult('', 0);
    }

    /** @param null|FilteredSummary $summary */
    public function setFilteredSummary(?array $summary): void {
        $this->filteredSummary = $summary;
    }

    /**
     * The range totals of result $resultId, once read; a newer result ignores them.
     *
     * @param RangeSummary $summary
     */
    public function setRangeSummary(string $resultId, array $summary): bool {
        if ($resultId === '' || $resultId !== $this->resultId) {
            return false;
        }
        $this->rangeSummary = $summary;
        $this->rangePending = false;

        return true;
    }

    public function hasResult(): bool {
        return $this->resultId !== '' && ($this->tableHtml !== '' || $this->command !== '');
    }

    /** Whether the store still holds this result's chunks. */
    public function hasPayload(): bool {
        return $this->resultId !== '' && isset(self::$payloads[$this->resultId]);
    }

    /** Row chunk $index as nfsen-table takes it; null once it is out of range or dropped. */
    public function rowChunk(int $index): ?string {
        return self::piece($this->resultId, 'rows', $index);
    }

    /** Piece $index of the kept output, escaped, as the Raw output tab appends it. */
    public function rawChunk(int $index): ?string {
        $text = self::piece($this->resultId, 'raw', $index);

        return $text === null
            ? null
            : '<span data-chunk="' . $index . '">' . htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5) . '</span>';
    }

    /**
     * Copies what the result keeps once the run's records are gone, so the copies take the
     * heap's first free pages rather than pinning the ones the parse spread over.
     */
    public function settle(): void {
        $this->tableHtml = self::copy($this->tableHtml);
        if (isset(self::$payloads[$this->resultId])) {
            self::$payloads[$this->resultId] = self::copy(self::$payloads[$this->resultId]);
        }
    }

    /** The table for a render that sends it: with the first chunk inside, which saves a round trip. */
    public function tableForSend(): string {
        $end = strrpos($this->tableHtml, '</nfsen-table>');
        $first = $end === false ? null : $this->rowChunk(0);

        return $first === null ? $this->tableHtml : substr_replace($this->tableHtml, $first . "\n", (int) $end, 0);
    }

    /** nfdump's output as the page keeps it; '' once the store dropped it. */
    public function rawOutput(): string {
        $text = '';
        for ($i = 0; $i < $this->rawChunks; ++$i) {
            $piece = self::piece($this->resultId, 'raw', $i);
            if ($piece === null) {
                return '';
            }
            $text .= $piece;
        }

        return $text;
    }

    public function rawTruncated(): bool {
        return $this->rawBytes > $this->rawKept;
    }

    /** 'flows' for listed records, 'rows' for aggregated ones, singular for one. */
    public function countLabel(): string {
        $noun = ($this->returnedSummary['aggregated'] ?? false) ? 'row' : 'flow';

        return $this->count === 1 ? $noun : $noun . 's';
    }

    public function restoreSignals(Context $c): void {
        $c->getSignal('flows_count')?->setValue($this->count, broadcast: false);
        $c->getSignal('flows_count_label')?->setValue($this->countLabel(), broadcast: false);
    }

    /** Small by design: the chunks stay in the store, under its budget, not in app-global state. */
    public function snapshot(): array {
        if ($this->isEmpty()) {
            return [];
        }
        if ($this->tableGz === '' && $this->tableHtml !== '') {
            $this->tableGz = self::compress($this->tableHtml);
        }

        return [
            'table' => $this->tableGz,
            'count' => $this->count,
            'resultId' => $this->resultId,
            'notifications' => $this->notifications,
            'limit' => $this->limit,
            'command' => $this->command,
            'notes' => $this->notes,
            'rawBytes' => $this->rawBytes,
            'rawKept' => $this->rawKept,
            'rowChunks' => $this->rowChunks,
            'rawChunks' => $this->rawChunks,
            'rowsLost' => $this->rowsLost,
            'rawLost' => $this->rawLost,
            'elapsed' => $this->elapsed,
            'returnedSummary' => $this->returnedSummary,
            'rangeSummary' => $this->rangeSummary,
            'filteredSummary' => $this->filteredSummary,
            'fingerprint' => $this->fingerprint,
            'totalsFingerprint' => $this->totalsFingerprint,
            'ranAt' => $this->ranAt,
            'windowStart' => $this->windowStart,
            'windowEnd' => $this->windowEnd,
            'live' => $this->live,
        ];
    }

    public function restore(array $data): void {
        $gz = self::stringFrom($data['table'] ?? '');
        $html = $gz !== '' ? gzuncompress($gz) : '';
        $this->tableHtml = \is_string($html) ? $html : '';
        $this->tableGz = $this->tableHtml !== '' ? $gz : '';
        $this->count = self::intFrom($data['count'] ?? null);
        $this->resultId = self::stringFrom($data['resultId'] ?? '');
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
        $this->limit = self::intFrom($data['limit'] ?? null);
        $this->command = self::stringFrom($data['command'] ?? '');
        $this->notes = array_values(array_filter(\is_array($data['notes'] ?? null) ? $data['notes'] : [], \is_string(...)));
        $this->rawBytes = self::intFrom($data['rawBytes'] ?? null);
        $this->rawKept = self::intFrom($data['rawKept'] ?? null);
        $this->rowChunks = self::intFrom($data['rowChunks'] ?? null);
        $this->rawChunks = self::intFrom($data['rawChunks'] ?? null);
        $this->rowsLost = ($data['rowsLost'] ?? false) === true;
        $this->rawLost = ($data['rawLost'] ?? false) === true;
        $this->elapsed = \is_float($data['elapsed'] ?? null) ? $data['elapsed'] : 0.0;

        /** @var null|ReturnedSummary $returned the snapshot comes from this class */
        $returned = \is_array($data['returnedSummary'] ?? null) ? $data['returnedSummary'] : null;

        /** @var null|RangeSummary $range */
        $range = \is_array($data['rangeSummary'] ?? null) ? $data['rangeSummary'] : null;

        /** @var null|FilteredSummary $filtered */
        $filtered = \is_array($data['filteredSummary'] ?? null) ? $data['filteredSummary'] : null;
        $this->returnedSummary = $returned;
        $this->rangeSummary = $range;
        $this->rangePending = false;
        $this->filteredSummary = $filtered;
        $this->fingerprint = self::stringFrom($data['fingerprint'] ?? '');
        $this->totalsFingerprint = self::stringFrom($data['totalsFingerprint'] ?? '');
        $this->ranAt = self::intFrom($data['ranAt'] ?? null);
        $this->windowStart = self::intFrom($data['windowStart'] ?? null);
        $this->windowEnd = self::intFrom($data['windowEnd'] ?? null);
        $this->live = ($data['live'] ?? false) === true;
    }

    public function isEmpty(): bool {
        return $this->tableHtml === '' && $this->command === '';
    }

    /**
     * Drops stored results, least recently used first, until $fits() holds.
     *
     * @param \Closure(): bool $fits
     */
    public static function makeRoom(\Closure $fits): bool {
        while (!$fits()) {
            if (self::$payloads === []) {
                return false;
            }
            self::forget((string) array_key_first(self::$payloads));
        }

        return true;
    }

    /** Compressed bytes the store holds for every tab. */
    public static function storedBytes(): int {
        return self::$payloadBytes;
    }

    /**
     * @param list<string> $rows compressed
     * @param list<string> $raw  compressed
     */
    private static function keep(string $id, array $rows, array $raw): void {
        $pieces = [...$rows, ...$raw];
        $payload = pack('N2', \count($rows), \count($raw)) . pack('N*', ...array_map(\strlen(...), $pieces)) . implode('', $pieces);
        self::$payloads[$id] = $payload;
        self::$payloadBytes += \strlen($payload);
        while (self::$payloadBytes > self::PAYLOAD_BUDGET && \count(self::$payloads) > 1) {
            self::forget((string) array_key_first(self::$payloads));
        }
    }

    private static function forget(string $id): void {
        if ($id !== '' && isset(self::$payloads[$id])) {
            self::$payloadBytes -= \strlen(self::$payloads[$id]);
            unset(self::$payloads[$id]);
        }
    }

    /** Piece $index of kind 'rows' or 'raw' of result $id, expanded; the result becomes the most recently used. */
    private static function piece(string $id, string $kind, int $index): ?string {
        $payload = self::$payloads[$id] ?? null;
        if ($payload === null || $index < 0) {
            return null;
        }
        unset(self::$payloads[$id]);
        self::$payloads[$id] = $payload;

        /** @var array{1: int, 2: int} $counts */
        $counts = unpack('N2', $payload);
        $position = $kind === 'rows' ? $index : $counts[1] + $index;
        if ($index >= ($kind === 'rows' ? $counts[1] : $counts[2])) {
            return null;
        }

        /** @var array<int, int> $lengths 1-based */
        $lengths = unpack('N*', substr($payload, 8, 4 * ($counts[1] + $counts[2])));
        $offset = 8 + 4 * \count($lengths);
        for ($i = 1; $i <= $position; ++$i) {
            $offset += $lengths[$i];
        }

        return self::expand(substr($payload, $offset, $lengths[$position + 1]));
    }

    /** A new allocation of $text; str_repeat() always allocates, even for one repeat. */
    private static function copy(string $text): string {
        return $text === '' ? '' : str_repeat($text, 1);
    }

    /**
     * The first RAW_OUTPUT_LIMIT bytes in compressed pieces of RAW_CHUNK_BYTES, each cut at a
     * line end when there is one, and the bytes they hold.
     *
     * @return array{list<string>, int}
     */
    private static function rawPieces(string $raw): array {
        $kept = self::cutAt($raw, 0, self::RAW_OUTPUT_LIMIT);
        $pieces = [];
        for ($offset = 0, $length = \strlen($kept); $offset < $length; $offset += \strlen($piece)) {
            $piece = self::cutAt($kept, $offset, self::RAW_CHUNK_BYTES);
            $pieces[] = self::compress($piece);
        }

        return [$pieces, \strlen($kept)];
    }

    /** Up to $bytes of $text from $offset, ending after a line end unless it reaches the end. */
    private static function cutAt(string $text, int $offset, int $bytes): string {
        $piece = substr($text, $offset, $bytes);
        if ($offset + \strlen($piece) >= \strlen($text)) {
            return $piece;
        }
        $newline = strrpos($piece, "\n");

        return $newline !== false ? substr($piece, 0, $newline + 1) : $piece;
    }

    private static function compress(string $data): string {
        $gz = gzcompress($data, 6);

        return $gz === false ? '' : $gz;
    }

    private static function expand(string $gz): string {
        $data = $gz === '' ? '' : gzuncompress($gz);

        return \is_string($data) ? $data : '';
    }

    private static function intFrom(mixed $value): int {
        return \is_int($value) ? $value : 0;
    }
}
