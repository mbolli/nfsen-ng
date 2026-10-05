<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\query\FlowsQuery;
use Mbolli\PhpVia\Context;

/**
 * Per-tab results of the Flows page (4.3.3). A list's rows and nfdump's output wait compressed in
 * stores every tab shares, under one byte budget; the tab keeps its sort, columns and first rows.
 *
 * @phpstan-import-type ReturnedSummary from FlowsQuery
 * @phpstan-import-type FilteredTotals from FlowsQuery
 *
 * @phpstan-type Totals array{flows: float, packets: float, bytes: float}
 * @phpstan-type RangeSummary array{available: bool, reason: string, totals: array<string, Totals>}
 * @phpstan-type FilteredSummary array{totals: FilteredTotals, command: string, elapsed: float, fingerprint: string, ranAt: int}
 * @phpstan-type Run array{resultId?: string, limit?: int, command?: string, notes?: list<string>,
 *                        rawOutput?: string, rawBytes?: int, elapsed?: float, returnedSummary?: ?ReturnedSummary,
 *                        rangeSummary?: ?RangeSummary, rangePending?: bool, fingerprint?: string, totalsFingerprint?: string,
 *                        ranAt?: int, start?: int, end?: int, live?: bool, mode?: 'html'|'list', itemSize?: int, browserTz?: string}
 */
final class FlowsState extends PageState {
    /** The page keeps at most this much of nfdump's stdout (4.3.2). */
    public const int RAW_OUTPUT_LIMIT = 5 * 1024 * 1024;

    /** The kept output goes out in pieces of about this size, one event each. */
    public const int RAW_CHUNK_BYTES = 512 * 1024;

    /** Compressed output and FlowRowStore's rows that all tabs of a worker keep together. */
    public const int PAYLOAD_BUDGET = 12 * 1024 * 1024;

    /** A column key as a request, a Run or a snapshot may name it: nfdump's json keys and its csv names. */
    public const string KEY_PATTERN = '/^[A-Za-z0-9_]{1,64}$/';

    /** The most hidden columns a tab keeps. */
    public const int MAX_HIDDEN = 64;

    /** An 'html' result as a render sends it: the empty state, or text nfdump printed that is no records. */
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

    /** Chunks of the kept output. */
    public int $rawChunks = 0;

    /** Set once the stores dropped rows or chunks the client still needed. */
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

    /** 'list' when the rows are in FlowRowStore and show in sb-virtual-scroll, 'html' for tableHtml. */
    public string $mode = 'html';

    /** The tab's sort of the list: a column key and 'asc' or 'desc', '' for file order. */
    public string $sortKey = '';

    public string $sortDir = '';

    /**
     * The sorts that made the order, each key once at its last use: an earlier sort by the same key
     * no longer decides anything. A revival replays them.
     *
     * @var list<array{0: string, 1: 'asc'|'desc'}>
     */
    public array $sortChain = [];

    /** The list's order as FlowRowStore::order() gives it, '' for file order, null until rebuilt. */
    public ?string $order = '';

    /** The window the list last received: its first row and how many rows were asked for. */
    public int $lastOffset = 0;

    public int $lastCount = 0;

    /** The first FlowRows::INITIAL_ROWS rows in the tab's order and columns, for the render that sends the list. */
    public string $firstRows = '';

    /** Row height of the list in px, fixed per result. */
    public int $itemSize = 0;

    /** The browser's time zone at the Run, a zone name or ''. */
    public string $browserTz = '';

    /**
     * Column keys the tab hides, also keys this result lacks.
     *
     * @var list<string>
     */
    public array $hiddenColumns = [];

    /**
     * Every column of the list in order, kept here so the Columns picker outlives the store's rows.
     *
     * @var list<array{key: string, title: string}>
     */
    public array $columns = [];

    /**
     * result id => its chunk count, lengths and gzcompress()ed chunks in one string, least recently
     * used first. One allocation per result pins fewer heap chunks than one per chunk.
     *
     * @var array<string, string>
     */
    private static array $payloads = [];

    private static int $payloadBytes = 0;

    /** @var array<string, int> result id => tick() of the payload's last use */
    private static array $payloadUsed = [];

    /** Orders the uses of payloads and row stores, which share one budget. */
    private static int $clock = 0;

    /** tableHtml compressed once, for the revival snapshot. */
    private string $tableGz = '';

    /** @param Run $run */
    public function setResult(string $tableHtml, int $count, array $run = []): void {
        self::forget($this->resultId);
        if (($run['resultId'] ?? '') !== $this->resultId) {
            FlowRowStore::forget($this->resultId);
        }
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
        $this->mode = ($run['mode'] ?? 'html') === 'list' ? 'list' : 'html';
        $this->itemSize = $run['itemSize'] ?? 0;
        $this->browserTz = $run['browserTz'] ?? '';
        $this->unsorted();
        $this->firstRows = '';
        $this->lastOffset = 0;
        $this->lastCount = 0;
        $this->columns = [];

        $raw = $run['rawOutput'] ?? '';
        $this->rawBytes = max($run['rawBytes'] ?? 0, \strlen($raw));
        [$pieces, $this->rawKept] = self::rawPieces($raw);
        $this->rawChunks = \count($pieces);
        if ($pieces !== []) {
            self::keep($this->resultId, $pieces);
        }
    }

    public function clearResult(): void {
        $this->setResult('', 0);
    }

    /** Frees the tab's stored rows and output before a new result takes their room in the budget. */
    public function releaseStored(): void {
        self::forget($this->resultId);
        FlowRowStore::forget($this->resultId);
    }

    /** Takes the list's columns from FlowRowStore while it holds the rows. */
    public function keepColumns(): void {
        $columns = FlowRowStore::columns($this->resultId);
        $this->columns = [];
        foreach ($columns['keys'] ?? [] as $key) {
            $this->columns[] = ['key' => $key, 'title' => $columns['titles'][$key] ?? $key];
        }
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
        return $this->resultId !== '' && ($this->tableHtml !== '' || $this->command !== '' || $this->mode === 'list');
    }

    /** Whether the store still holds this result's output. */
    public function hasPayload(): bool {
        return $this->resultId !== '' && isset(self::$payloads[$this->resultId]);
    }

    /** Whether the rows the page shows are still there: FlowRowStore's for a list; an 'html' result keeps its own. */
    public function hasRows(): bool {
        return $this->mode !== 'list' || FlowRowStore::has($this->resultId);
    }

    /** The zone the list writes its times in: the capture zone for a 'server' display, else the Run's browser zone, else UTC. */
    public function zone(): \DateTimeZone {
        return FlowRowStore::zone(
            isset(Config::$settings) ? Config::$settings->displayTimezone : 'browser',
            Config::nfcapdTimezone()->getName(),
            $this->browserTz,
        );
    }

    /**
     * The result's column keys less the hidden ones, in the result's order.
     *
     * @return list<string>
     */
    public function shownKeys(): array {
        return array_values(array_diff(array_column($this->columns, 'key'), $this->hiddenColumns));
    }

    /**
     * The keys of $value (a list or comma-separated) that KEY_PATTERN allows, each once; past
     * MAX_HIDDEN, the keys in $prefer come first and the rest are dropped.
     *
     * @param list<string> $prefer
     *
     * @return list<string>
     */
    public static function hiddenFrom(mixed $value, array $prefer = []): array {
        if (\is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }
        $keys = [];
        foreach (\is_array($value) ? $value : [] as $key) {
            if (\is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1) {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));
        if (\count($keys) > self::MAX_HIDDEN) {
            $keys = \array_slice([...array_intersect($keys, $prefer), ...array_diff($keys, $prefer)], 0, self::MAX_HIDDEN);
        }

        return $keys;
    }

    /** The list's order, rebuilt from the tab's sorts after a revival; null once the rows are gone. */
    public function currentOrder(): ?string {
        if ($this->order === null) {
            $order = '';
            foreach ($this->sortChain as [$key, $dir]) {
                $order = FlowRowStore::order($this->resultId, $key, $dir, $order);
                if ($order === null) {
                    break;
                }
            }
            $this->order = $order;
        }

        return $this->order;
    }

    /** The list sorted by $key in $dir, giving $order. */
    public function sortBy(string $key, string $dir, string $order): void {
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $this->sortChain = [...array_values(array_filter($this->sortChain, static fn (array $sort): bool => $sort[0] !== $key)), [$key, $dir]];
        $this->sortKey = $key;
        $this->sortDir = $dir;
        $this->order = $order;
    }

    /** File order: no sort. */
    public function unsorted(): void {
        $this->sortChain = [];
        $this->sortKey = $this->sortDir = '';
        $this->order = '';
    }

    /** Piece $index of the kept output, escaped, as the Raw output tab appends it. */
    public function rawChunk(int $index): ?string {
        $text = self::piece($this->resultId, $index);

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
        $this->firstRows = self::copy($this->firstRows);
        if (isset(self::$payloads[$this->resultId])) {
            self::$payloads[$this->resultId] = self::copy(self::$payloads[$this->resultId]);
        }
    }

    /** nfdump's output as the page keeps it; '' once the store dropped it. */
    public function rawOutput(): string {
        $text = '';
        for ($i = 0; $i < $this->rawChunks; ++$i) {
            $piece = self::piece($this->resultId, $i);
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
        $c->getSignal('flows_count')?->setValue($this->count);
        $c->getSignal('flows_count_label')?->setValue($this->countLabel());
    }

    /** Small by design: rows and chunks stay in their stores, under the budget, not in app-global state. */
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
            'mode' => $this->mode,
            'sortKey' => $this->sortKey,
            'sortDir' => $this->sortDir,
            'sortChain' => $this->sortChain,
            'itemSize' => $this->itemSize,
            'browserTz' => $this->browserTz,
            'hiddenColumns' => $this->hiddenColumns,
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
        $this->mode = ($data['mode'] ?? '') === 'list' ? 'list' : 'html';
        $sortKey = self::stringFrom($data['sortKey'] ?? '');
        $this->sortKey = preg_match(self::KEY_PATTERN, $sortKey) === 1 ? $sortKey : '';
        $this->sortDir = ($data['sortDir'] ?? '') === 'desc' ? 'desc' : ($this->sortKey !== '' ? 'asc' : '');
        $this->itemSize = self::intFrom($data['itemSize'] ?? null);
        $browserTz = self::stringFrom($data['browserTz'] ?? '');
        $this->browserTz = FlowRowStore::isZone($browserTz) ? $browserTz : '';
        $this->hiddenColumns = self::hiddenFrom(\is_array($data['hiddenColumns'] ?? null) ? $data['hiddenColumns'] : []);
        $this->sortChain = [];
        foreach (\is_array($data['sortChain'] ?? null) ? $data['sortChain'] : [] as $sort) {
            if (\is_array($sort) && \is_string($sort[0] ?? null) && preg_match(self::KEY_PATTERN, $sort[0]) === 1 && \in_array($sort[1] ?? null, ['asc', 'desc'], true)) {
                $this->sortChain[] = [$sort[0], $sort[1]];
            }
        }
        if ($this->sortKey === '') {
            $this->sortChain = [];
        } elseif (end($this->sortChain) !== [$this->sortKey, $this->sortDir]) {
            $this->sortChain = [[$this->sortKey, $this->sortDir === 'desc' ? 'desc' : 'asc']];
        }
        // Rebuilt on demand: the next window sorts again, and a render without first rows lets the list ask.
        $this->order = null;
        $this->firstRows = '';
        $this->lastOffset = 0;
        $this->lastCount = 0;
        $this->keepColumns();
    }

    public function isEmpty(): bool {
        return $this->tableHtml === '' && $this->command === '' && !($this->mode === 'list' && $this->resultId !== '');
    }

    /**
     * Drops stored results, payloads and row stores alike, least recently used first, until $fits() holds.
     *
     * @param \Closure(): bool $fits
     */
    public static function makeRoom(\Closure $fits): bool {
        while (!$fits()) {
            if (!self::evictOldest()) {
                return false;
            }
        }

        return true;
    }

    /** Compressed bytes the stores hold for every tab: the output and FlowRowStore's rows. */
    public static function storedBytes(): int {
        return self::$payloadBytes + FlowRowStore::bytes();
    }

    /** Compressed bytes the store holds for result $id's output. */
    public static function payloadBytesOf(string $id): int {
        return isset(self::$payloads[$id]) ? \strlen(self::$payloads[$id]) : 0;
    }

    /** The next use in the order the shared budget evicts by. */
    public static function tick(): int {
        return ++self::$clock;
    }

    /** Evicts the least recently used payloads and row stores, never result $keep's, until both fit the budget. */
    public static function enforceBudget(string $keep): void {
        while (self::storedBytes() > self::PAYLOAD_BUDGET) {
            if (!self::evictOldest($keep)) {
                return;
            }
        }
    }

    /** @param list<string> $raw compressed */
    private static function keep(string $id, array $raw): void {
        $payload = pack('N', \count($raw)) . pack('N*', ...array_map(\strlen(...), $raw)) . implode('', $raw);
        self::$payloads[$id] = $payload;
        self::$payloadUsed[$id] = self::tick();
        self::$payloadBytes += \strlen($payload);
        self::enforceBudget($id);
    }

    private static function forget(string $id): void {
        if ($id !== '' && isset(self::$payloads[$id])) {
            self::$payloadBytes -= \strlen(self::$payloads[$id]);
            unset(self::$payloads[$id], self::$payloadUsed[$id]);
        }
    }

    /** Drops the least recently used payload or row store other than $keep's; false when there is none. */
    private static function evictOldest(string $keep = ''): bool {
        $payload = null;
        foreach (self::$payloads as $id => $_) {
            if ($id !== $keep) {
                $payload = (string) $id;

                break;
            }
        }
        [$rows, $rowsUsed] = FlowRowStore::oldest($keep);
        if ($payload === null && $rows === null) {
            return false;
        }
        if ($rows === null || ($payload !== null && (self::$payloadUsed[$payload] ?? 0) <= $rowsUsed)) {
            self::forget((string) $payload);
        } else {
            FlowRowStore::forget($rows);
        }

        return true;
    }

    /** Piece $index of result $id's output, expanded; the result becomes the most recently used. */
    private static function piece(string $id, int $index): ?string {
        $payload = self::$payloads[$id] ?? null;
        if ($payload === null || $index < 0) {
            return null;
        }
        unset(self::$payloads[$id]);
        self::$payloads[$id] = $payload;
        self::$payloadUsed[$id] = self::tick();

        /** @var array{1: int} $count */
        $count = unpack('N', $payload);
        if ($index >= $count[1]) {
            return null;
        }

        /** @var array<int, int> $lengths 1-based */
        $lengths = unpack('N*', substr($payload, 4, 4 * $count[1]));
        $offset = 4 + 4 * $count[1];
        for ($i = 1; $i <= $index; ++$i) {
            $offset += $lengths[$i];
        }

        return self::expand(substr($payload, $offset, $lengths[$index + 1]));
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
