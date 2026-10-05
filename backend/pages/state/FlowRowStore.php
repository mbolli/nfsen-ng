<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\common\TableFormatter;

/**
 * The rows of Flows list results, each cell rendered once into compressed blocks of BLOCK_ROWS rows,
 * with sort keys and raw values per column. Worker-global, under FlowsState's budget.
 *
 * @phpstan-import-type Columns from FlowRows
 *
 * @phpstan-type Store array{count: int, columns: Columns, blob: string, offsets: list<int>, sort: array<string, string>,
 *                          raw: array<string, string>, bytes: int}
 * @phpstan-type Window array{html: string, blocks: int}
 * @phpstan-type Block array{payload: string, starts: list<int>, lengths: list<int>}
 */
final class FlowRowStore {
    public const int BLOCK_ROWS = 64;

    /** Column widths in ch: at least MIN_CH, at most MAX_CH. */
    public const int MIN_CH = 6;

    public const int MAX_CH = 40;

    /** An address column's cap: the longest IPv6 text (45 characters, INET6_ADDRSTRLEN) plus 2. */
    public const int ADDRESS_MAX_CH = 47;

    /** Rows an export expands blocks for at a time, so a sorted export holds a bounded part of the result. */
    private const int EXPORT_ROWS = 4096;

    /** @var array<string, Store> least recently used first */
    private static array $stores = [];

    /** @var array<string, int> result id => FlowsState::tick() of its last use */
    private static array $used = [];

    private static int $bytes = 0;

    /** @var array<string, null|\DateTimeZone> */
    private static array $zones = [];

    /**
     * Renders and keeps the rows of result $id; false when there is no array row to keep.
     *
     * @param list<mixed>          $rows
     * @param array<string, mixed> $options TableFormatter's options (linkIpAddresses, ipInfoActionUrl) and hiddenFields
     */
    public static function store(string $id, array $rows, array $options = []): bool {
        $data = array_values(array_filter($rows, \is_array(...)));
        if ($id === '' || $data === []) {
            return false;
        }
        self::forget($id);
        $options += ['linkIpAddresses' => true];
        $hidden = \is_array($options['hiddenFields'] ?? null) ? $options['hiddenFields'] : Table::HIDDEN_FIELDS;
        ['keys' => $keys, 'kinds' => $kinds] = Table::columnsOf($data, $hidden);

        $attributes = $sort = $raw = $lengths = $titles = $caps = [];
        foreach ($keys as $key) {
            $attributes[$key] = FlowRows::kindAttribute($kinds[$key]);
            $sort[$key] = $raw[$key] = '';
            $caps[$key] = $kinds[$key] === 'address' ? self::ADDRESS_MAX_CH : self::MAX_CH;
            $lengths[$key] = array_fill(0, $caps[$key] + 1, 0);
            $titles[$key] = Table::columnTitle($key);
        }

        $blob = '';
        $offsets = [];
        $cellLengths = [];
        $html = '';
        $inBlock = 0;
        foreach ($data as $i => $row) {
            $separator = $i === 0 ? '' : "\0";
            foreach ($keys as $key) {
                $value = $row[$key] ?? '';
                $content = TableFormatter::formatCellValue($value, $key, $options);
                $cell = '<div role="cell"' . $attributes[$key] . '>' . $content . '</div>';
                $cellLengths[] = \strlen($cell);
                $html .= $cell;
                $sort[$key] .= $separator . self::joinable(Table::scalar(TableFormatter::getSortValue($value, $key)));
                $raw[$key] .= $separator . self::joinable(Table::scalar($value));
                ++$lengths[$key][min($caps[$key], self::visibleLength($content))];
            }
            if (++$inBlock === self::BLOCK_ROWS) {
                $blob .= self::compress(pack('N*', ...$cellLengths) . $html);
                $offsets[] = \strlen($blob);
                $cellLengths = [];
                $html = '';
                $inBlock = 0;
            }
        }
        if ($inBlock > 0) {
            $blob .= self::compress(pack('N*', ...$cellLengths) . $html);
            $offsets[] = \strlen($blob);
        }

        $count = \count($data);
        $widths = [];
        foreach ($keys as $key) {
            $widths[$key] = self::width($titles[$key], $lengths[$key], $count, $kinds[$key] === 'address');
            $sort[$key] = self::compress($sort[$key]);
            $raw[$key] = self::compress($raw[$key]);
        }
        $bytes = \strlen($blob) + 4 * \count($offsets) + array_sum(array_map(\strlen(...), $sort)) + array_sum(array_map(\strlen(...), $raw));

        self::$stores[$id] = [
            'count' => $count,
            'columns' => ['keys' => $keys, 'kinds' => $kinds, 'titles' => $titles, 'widths' => $widths],
            'blob' => $blob,
            'offsets' => $offsets,
            'sort' => $sort,
            'raw' => $raw,
            'bytes' => $bytes,
        ];
        self::$used[$id] = FlowsState::tick();
        self::$bytes += $bytes;
        FlowsState::enforceBudget($id);

        return true;
    }

    public static function has(string $id): bool {
        return isset(self::$stores[$id]);
    }

    public static function count(string $id): int {
        return self::$stores[$id]['count'] ?? 0;
    }

    /** @return null|Columns every column of the result, in order */
    public static function columns(string $id): ?array {
        return self::$stores[$id]['columns'] ?? null;
    }

    /**
     * The grid's columns for the shown keys: `minmax(<w>ch, 1fr)` each.
     *
     * @param list<string> $shown
     */
    public static function template(string $id, array $shown): string {
        $widths = self::$stores[$id]['columns']['widths'] ?? [];
        $template = [];
        foreach ($shown as $key) {
            if (isset($widths[$key])) {
                $template[] = \sprintf('minmax(%dch, 1fr)', $widths[$key]);
            }
        }

        return implode(' ', $template);
    }

    /**
     * Rows $offset to $offset + $count - 1 in $order ('' is file order, else pack('N*') file indices)
     * with the $shown columns' cells and times in $tz; each block is expanded once.
     *
     * @param list<string> $shown
     *
     * @return null|Window null once the store dropped the result
     */
    public static function window(string $id, int $offset, int $count, string $order, \DateTimeZone $tz, array $shown): ?array {
        $store = self::touch($id);
        if ($store === null) {
            return null;
        }
        $total = $store['count'];
        $offset = max(0, min($offset, $total));
        $count = max(0, min($count, FlowRows::MAX_WINDOW, $total - $offset));
        if ($count === 0) {
            return ['html' => '', 'blocks' => 0];
        }
        $width = \count($store['columns']['keys']);
        $positions = self::positions($store['columns']['keys'], $shown);
        $all = $positions === range(0, $width - 1);

        $blocks = [];
        $html = '';
        foreach (self::indices($order, $offset, $count) as $n => $index) {
            $b = intdiv($index, self::BLOCK_ROWS);
            $blocks[$b] ??= self::block($store, $b);
            $first = ($index - $b * self::BLOCK_ROWS) * $width;
            $html .= FlowRows::row($offset + $n, $all ? self::span($blocks[$b], $first, $width) : self::cells($blocks[$b], $first, $positions));
        }

        return ['html' => self::localise($html, $tz), 'blocks' => \count($blocks)];
    }

    /**
     * Every row of the result in $order, one list of the $keys columns' values each: with $enhanced
     * the text the list shows (times in $tz), else the raw values. Null once the store dropped the result.
     *
     * @param list<string> $keys
     *
     * @return null|\Generator<int, list<string>, mixed, void>
     */
    public static function exportRows(string $id, string $order, array $keys, \DateTimeZone $tz, bool $enhanced): ?\Generator {
        $store = self::touch($id);
        if ($store === null) {
            return null;
        }

        return $enhanced ? self::shownRows($store, $order, $keys, $tz) : self::rawRows($store, $order, $keys);
    }

    /**
     * The order of the list sorted by column $key in direction $dir ('asc' or 'desc'): stable
     * against $current, empty keys last in both directions, keys compared as nfsen-table does.
     *
     * @return null|string pack('N*') of file indices; null when the store or the column is missing
     */
    public static function order(string $id, string $key, string $dir, string $current = ''): ?string {
        $store = self::touch($id);
        if ($store === null || !isset($store['sort'][$key])) {
            return null;
        }
        $total = $store['count'];
        $keys = self::values($store['sort'][$key], $total);
        if ($keys === null) {
            return null;
        }
        $sequence = $current === '' ? range(0, $total - 1) : self::unpackIndices($current);

        $filled = $empty = [];
        foreach ($sequence as $index) {
            if (($keys[$index] ?? '') === '') {
                $empty[] = $index;
            } else {
                $filled[] = $index;
            }
        }
        $sorted = self::sortIndices($filled, $keys, $dir === 'desc');

        return pack('N*', ...$sorted, ...$empty);
    }

    /** Compressed bytes of result $id's rows, sort keys and raw values. */
    public static function bytesOf(string $id): int {
        return self::$stores[$id]['bytes'] ?? 0;
    }

    /** Compressed bytes of every stored result. */
    public static function bytes(): int {
        return self::$bytes;
    }

    /**
     * The least recently used result other than $keep, with the tick of its last use.
     *
     * @return array{0: ?string, 1: int}
     */
    public static function oldest(string $keep = ''): array {
        foreach (self::$stores as $id => $_) {
            if ($id !== $keep) {
                return [(string) $id, self::$used[$id] ?? 0];
            }
        }

        return [null, PHP_INT_MAX];
    }

    public static function forget(string $id): void {
        if (isset(self::$stores[$id])) {
            self::$bytes -= self::$stores[$id]['bytes'];
            unset(self::$stores[$id], self::$used[$id]);
        }
    }

    /** The zone of `<time>` texts: nfcapd's for a 'server' display, else the browser's when valid, else UTC. */
    public static function zone(string $display, string $nfcapd, string $browser): \DateTimeZone {
        $name = $display === 'server' ? $nfcapd : $browser;

        return self::validZone($name) ?? ($display === 'server' ? self::validZone($browser) : null) ?? new \DateTimeZone('UTC');
    }

    /** Whether $name is a zone \DateTimeZone accepts. */
    public static function isZone(string $name): bool {
        return self::validZone($name) !== null;
    }

    /**
     * Sorts $indices by $keys[index]: digit strings by value, numbers as numbers, the rest in
     * natural case-insensitive order; ties keep their place.
     *
     * @param list<int>    $indices
     * @param list<string> $keys
     *
     * @return list<int>
     */
    public static function sortIndices(array $indices, array $keys, bool $descending): array {
        if ($indices === []) {
            return [];
        }
        $digits = $numbers = true;
        $width = 0;
        foreach ($indices as $index) {
            $key = $keys[$index];
            if ($digits && ctype_digit($key)) {
                $width = max($width, \strlen(ltrim($key, '0')));
            } else {
                $digits = false;
            }
            if (!self::isNumber($key)) {
                $numbers = $digits = false;

                break;
            }
        }

        $positions = array_keys($indices);
        if ($digits) {
            $normalised = [];
            foreach ($indices as $index) {
                $normalised[] = str_pad(ltrim($keys[$index], '0'), $width, '0', STR_PAD_LEFT);
            }
            array_multisort($normalised, $descending ? SORT_DESC : SORT_ASC, SORT_STRING, $positions, SORT_ASC, SORT_NUMERIC, $indices);

            return $indices;
        }
        if ($numbers) {
            $normalised = [];
            foreach ($indices as $index) {
                $normalised[] = (float) $keys[$index];
            }
            array_multisort($normalised, $descending ? SORT_DESC : SORT_ASC, SORT_NUMERIC, $positions, SORT_ASC, SORT_NUMERIC, $indices);

            return $indices;
        }

        $factor = $descending ? -1 : 1;
        usort($indices, static fn (int $a, int $b): int => $factor * self::compare($keys[$a], $keys[$b]));

        return $indices;
    }

    /** nfsen-table.js compareValues(), with strnatcasecmp() for text. */
    public static function compare(string $a, string $b): int {
        if (ctype_digit($a) && ctype_digit($b)) {
            $x = ltrim($a, '0');
            $y = ltrim($b, '0');

            return (\strlen($x) <=> \strlen($y)) ?: strcmp($x, $y) <=> 0;
        }
        if (self::isNumber($a) && self::isNumber($b)) {
            return (float) $a <=> (float) $b;
        }

        return strnatcasecmp($a, $b) <=> 0;
    }

    private static function isNumber(string $value): bool {
        return preg_match('/^-?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?$/i', $value) === 1;
    }

    /**
     * The shown text of every row's $keys cells, a part of the order at a time.
     *
     * @param Store        $store
     * @param list<string> $keys
     *
     * @return \Generator<int, list<string>, mixed, void>
     */
    private static function shownRows(array $store, string $order, array $keys, \DateTimeZone $tz): \Generator {
        $width = \count($store['columns']['keys']);
        $positions = [];
        foreach ($keys as $key) {
            $position = array_search($key, $store['columns']['keys'], true);
            $positions[] = \is_int($position) ? $position : null;
        }
        $memo = [];
        for ($offset = 0, $total = $store['count']; $offset < $total; $offset += self::EXPORT_ROWS) {
            $indices = self::indices($order, $offset, min(self::EXPORT_ROWS, $total - $offset));
            $needed = [];
            foreach ($indices as $index) {
                $needed[intdiv($index, self::BLOCK_ROWS)] = true;
            }
            $blocks = [];
            foreach (array_keys($needed) as $b) {
                $blocks[$b] = self::block($store, $b);
            }
            foreach ($indices as $index) {
                $b = intdiv($index, self::BLOCK_ROWS);
                $first = ($index - $b * self::BLOCK_ROWS) * $width;
                $row = [];
                foreach ($positions as $j) {
                    $row[] = $j === null ? '' : self::text(self::span($blocks[$b], $first + $j, 1), $tz, $memo);
                }

                yield $row;
            }
            unset($blocks);
        }
    }

    /**
     * The raw values of every row's $keys columns.
     *
     * @param Store        $store
     * @param list<string> $keys
     *
     * @return \Generator<int, list<string>, mixed, void>
     */
    private static function rawRows(array $store, string $order, array $keys): \Generator {
        $total = $store['count'];
        $columns = [];
        foreach ($keys as $key) {
            $columns[] = isset($store['raw'][$key]) ? self::values($store['raw'][$key], $total) ?? [] : [];
        }
        foreach (self::indices($order, 0, $total) as $index) {
            $row = [];
            foreach ($columns as $values) {
                $row[] = $values[$index] ?? '';
            }

            yield $row;
        }
    }

    /**
     * File indices of rows $offset to $offset + $count - 1 in $order.
     *
     * @return list<int>
     */
    private static function indices(string $order, int $offset, int $count): array {
        if ($count <= 0) {
            return [];
        }

        return $order === '' ? range($offset, $offset + $count - 1) : self::unpackIndices(substr($order, 4 * $offset, 4 * $count));
    }

    /** @return list<int> */
    private static function unpackIndices(string $packed): array {
        if ($packed === '') {
            return [];
        }

        /** @var array<int, int> $unpacked */
        $unpacked = unpack('N*', $packed) ?: [];

        return array_values($unpacked);
    }

    /**
     * Column positions of the $shown keys the result has, in the order given.
     *
     * @param list<string> $keys
     * @param list<string> $shown
     *
     * @return list<int>
     */
    private static function positions(array $keys, array $shown): array {
        $positions = [];
        foreach ($shown as $key) {
            $position = array_search($key, $keys, true);
            if (\is_int($position)) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * The cells at $positions of the row whose first cell is cell $first of $block.
     *
     * @param Block     $block
     * @param list<int> $positions
     */
    private static function cells(array $block, int $first, array $positions): string {
        $html = '';
        foreach ($positions as $j) {
            $html .= self::span($block, $first + $j, 1);
        }

        return $html;
    }

    /**
     * Cells $first to $first + $n - 1 of $block, which lie side by side.
     *
     * @param Block $block
     */
    private static function span(array $block, int $first, int $n): string {
        $last = $first + $n - 1;
        if (!isset($block['starts'][$first], $block['starts'][$last])) {
            return '';
        }

        return substr($block['payload'], $block['starts'][$first], $block['starts'][$last] + $block['lengths'][$last] - $block['starts'][$first]);
    }

    /**
     * Block $b of $store: its cells' HTML and where each cell starts in it.
     *
     * @param Store $store
     *
     * @return Block
     */
    private static function block(array $store, int $b): array {
        $start = $b === 0 ? 0 : ($store['offsets'][$b - 1] ?? 0);
        $end = $store['offsets'][$b] ?? $start;
        $payload = gzuncompress(substr($store['blob'], $start, $end - $start));
        $cells = min(self::BLOCK_ROWS, $store['count'] - $b * self::BLOCK_ROWS) * \count($store['columns']['keys']);
        if (!\is_string($payload) || $cells <= 0) {
            return ['payload' => '', 'starts' => [], 'lengths' => []];
        }

        /** @var array<int, int> $unpacked */
        $unpacked = unpack('N' . $cells, $payload) ?: [];
        $lengths = array_values($unpacked);
        $starts = [];
        $position = 4 * $cells;
        foreach ($lengths as $length) {
            $starts[] = $position;
            $position += $length;
        }

        return ['payload' => $payload, 'starts' => $starts, 'lengths' => $lengths];
    }

    /**
     * A cell's text as the list shows it: times in $tz, tags stripped, entities decoded, every
     * run of white space one space, trimmed (nfsen-table's export).
     *
     * @param array<string, string> $memo epoch => its time in $tz
     */
    private static function text(string $cell, \DateTimeZone $tz, array &$memo): string {
        if (str_contains($cell, '<time data-epoch="')) {
            $cell = self::localise($cell, $tz, $memo);
        }
        $text = html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/[\s\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $text) ?? $text);
    }

    /**
     * $html's `<time data-epoch>` texts as the time in $tz, each epoch formatted once.
     *
     * @param array<string, string> $memo epoch => its time in $tz
     */
    private static function localise(string $html, \DateTimeZone $tz, array &$memo = []): string {
        if (!str_contains($html, '<time data-epoch="')) {
            return $html;
        }

        return (string) preg_replace_callback('~<time data-epoch="(\d+)">[^<]*</time>~', static function (array $m) use (&$memo, $tz): string {
            return '<time data-epoch="' . $m[1] . '">'
                . ($memo[$m[1]] ??= new \DateTimeImmutable('@' . $m[1])->setTimezone($tz)->format('Y-m-d H:i:s')) . '</time>';
        }, $html);
    }

    /**
     * A compressed column of $total values joined by NUL, expanded; null when it does not hold $total.
     *
     * @return null|list<string>
     */
    private static function values(string $compressed, int $total): ?array {
        $expanded = gzuncompress($compressed);
        $values = explode("\0", \is_string($expanded) ? $expanded : '');

        return \count($values) === $total ? $values : null;
    }

    /** A value that a NUL-joined column can hold: a NUL reads as U+FFFD, as an HTML parser shows it. */
    private static function joinable(string $value): string {
        return str_contains($value, "\0") ? str_replace("\0", "\u{FFFD}", $value) : $value;
    }

    /**
     * The store of result $id, now the most recently used.
     *
     * @return null|Store
     */
    private static function touch(string $id): ?array {
        $store = self::$stores[$id] ?? null;
        if ($store === null) {
            return null;
        }
        unset(self::$stores[$id]);
        self::$stores[$id] = $store;
        self::$used[$id] = FlowsState::tick();

        return $store;
    }

    /**
     * clamp(MIN_CH, max(title + 2, p95 of the cells' text) + 2, MAX_CH). An address column takes
     * its longest text instead, up to ADDRESS_MAX_CH: a few IPv6 addresses among IPv4 ones would be cut otherwise.
     *
     * @param array<int, int> $lengths text length => cells
     */
    private static function width(string $title, array $lengths, int $count, bool $longest = false): int {
        $needed = $longest ? $count : (int) ceil(0.95 * $count);
        $p95 = 0;
        $seen = 0;
        foreach ($lengths as $length => $cells) {
            $seen += $cells;
            if ($seen >= $needed) {
                $p95 = $length;

                break;
            }
        }

        return max(self::MIN_CH, min($longest ? self::ADDRESS_MAX_CH : self::MAX_CH, max(mb_strlen($title) + 2, $p95) + 2));
    }

    private static function visibleLength(string $content): int {
        if (!str_contains($content, '<') && !str_contains($content, '&')) {
            return mb_strlen($content);
        }

        return mb_strlen(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5));
    }

    private static function validZone(string $name): ?\DateTimeZone {
        if ($name === '') {
            return null;
        }
        if (!\array_key_exists($name, self::$zones)) {
            try {
                self::$zones[$name] = new \DateTimeZone($name);
            } catch (\Exception) {
                self::$zones[$name] = null;
            }
            if (\count(self::$zones) > 256) {
                self::$zones = [$name => self::$zones[$name]];
            }
        }

        return self::$zones[$name];
    }

    private static function compress(string $data): string {
        $gz = gzcompress($data, 6);

        return $gz === false ? '' : $gz;
    }
}
