<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Revival;
use mbolli\nfsen_ng\pages\state\FlowExports;
use mbolli\nfsen_ng\pages\state\FlowRowStore;
use mbolli\nfsen_ng\pages\state\FlowsState;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use starfederation\datastar\enums\ElementPatchMode;

/**
 * CSV, JSON and Print of the whole Flows list, built on the server from FlowRowStore in the tab's
 * order and columns, and pulled by the browser in pieces like the raw output.
 */
final class FlowExportActions {
    private const array FORMATS = ['csv', 'json', 'print'];
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function register(Context $c, Via $app, PageStates $states): void {
        $c->action(static function (Context $c) use ($app, $states): void {
            Revival::restore($c, $app, $states);
            self::export($c, $states->flows);
        }, 'flows-export');

        $c->action(static function (Context $c) use ($app, $states): void {
            Revival::restore($c, $app, $states);
            self::chunk($c);
        }, 'flows-export-chunk');
    }

    /** Builds the file of `format` (`enhanced` 1 for the text the list shows) and appends its element to #flowsExports. */
    public static function export(Context $c, FlowsState $flows): void {
        $result = $c->input('result');
        $format = $c->input('format');
        if (!\is_string($result) || $result === '' || $result !== $flows->resultId || $flows->mode !== 'list' || !\in_array($format, self::FORMATS, true)) {
            return;
        }
        $order = $flows->currentOrder();
        $keys = $flows->shownKeys();
        $rows = $order === null ? null : FlowRowStore::exportRows($result, $order, $keys, $flows->zone(), $format === 'print' || $c->input('enhanced') === '1');
        if ($rows === null) {
            $flows->rowsLost = true;
            $c->sync();

            return;
        }
        $pieces = self::pieces($format, self::titles($keys), $rows);
        $token = FlowExports::add($pieces);
        $name = FlowActions::exportName($flows->windowStart, $flows->windowEnd) . ($format === 'print' ? '' : ".{$format}");
        $chunkUrl = $c->getAction('flows-export-chunk')?->url() ?? '';
        $html = \sprintf(
            '<pre hidden id="flowsExportData-%1$s" class="flows-export-data" data-chunks="%2$d" data-format="%3$s" data-name="%4$s"'
            . ' data-effect="window.nfsenFlowsList?.pull(el, (n) =&gt; @post(\'%5$s?export=%1$s&amp;chunk=\' + n, %6$s))"></pre>',
            $token,
            \count($pieces),
            $format,
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5),
            htmlspecialchars($chunkUrl, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5),
            htmlspecialchars(FlowRows::SLIM, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5),
        );
        $c->getPatchManager()->queuePatch([
            'type' => 'elements',
            'content' => $html,
            'selector' => '#flowsExports',
            'mode' => ElementPatchMode::Append,
        ]);
    }

    /** Appends piece `chunk` of export `export` as text to its element; an unknown one queues nothing. */
    public static function chunk(Context $c): void {
        $token = $c->input('export');
        $index = $c->input('chunk');
        if (!\is_string($token) || preg_match('/^[0-9a-f]{16}$/', $token) !== 1 || !\is_string($index) || !ctype_digit($index)) {
            return;
        }
        $text = FlowExports::piece($token, (int) $index);
        if ($text === null) {
            return;
        }
        $c->getPatchManager()->queuePatch([
            'type' => 'elements',
            'content' => '<span data-chunk="' . (int) $index . '">' . htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5) . '</span>',
            'selector' => "#flowsExportData-{$token}",
            'mode' => ElementPatchMode::Append,
        ]);
    }

    /**
     * The file's column titles: a repeated title gets its key, as the list's export always did.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public static function titles(array $keys): array {
        $seen = [];
        $titles = [];
        foreach ($keys as $key) {
            $title = Table::columnTitle($key);
            if (isset($seen[$title])) {
                $title .= " ({$key})";
            }
            $seen[$title] = true;
            $titles[] = $title;
        }

        return $titles;
    }

    /**
     * The file in pieces of at most RAW_CHUNK_BYTES, each a whole number of rows (one longer row stays whole).
     *
     * @param list<string>                $titles
     * @param iterable<int, list<string>> $rows
     *
     * @return list<string>
     */
    public static function pieces(string $format, array $titles, iterable $rows): array {
        [$head, $tail] = match ($format) {
            'csv' => [implode(',', array_map(self::csv(...), $titles)) . "\n", ''],
            'json' => ['[', ''],
            default => ['{"title":"Flows","columns":' . self::json($titles) . ',"rows":[', ']}'],
        };
        $pieces = [];
        $piece = $head;
        $first = true;
        foreach ($rows as $row) {
            $text = match ($format) {
                'csv' => implode(',', array_map(self::csv(...), $row)) . "\n",
                'json' => ($first ? "\n" : ",\n") . self::jsonObject($titles, $row),
                default => ($first ? '' : ',') . self::json($row),
            };
            $first = false;
            if ($piece !== '' && \strlen($piece) + \strlen($text) > FlowsState::RAW_CHUNK_BYTES) {
                $pieces[] = $piece;
                $piece = '';
            }
            $piece .= $text;
        }
        // JSON.stringify(rows, null, 2): an empty list is [], else the objects on their own lines.
        $piece .= $format === 'json' ? ($first ? ']' : "\n]") : $tail;
        $pieces[] = $piece;

        return $pieces;
    }

    private static function csv(string $value): string {
        return strpbrk($value, "\",\n\r") === false ? $value : '"' . str_replace('"', '""', $value) . '"';
    }

    /** @param list<string> $values */
    private static function json(array $values): string {
        return (string) json_encode($values, self::JSON_FLAGS);
    }

    /**
     * One row as JSON.stringify(rows, null, 2) writes it inside the array.
     *
     * @param list<string> $titles
     * @param list<string> $row
     */
    private static function jsonObject(array $titles, array $row): string {
        if ($titles === []) {
            return '  {}';
        }
        $members = [];
        foreach ($titles as $i => $title) {
            $members[] = '    ' . json_encode($title, self::JSON_FLAGS) . ': ' . json_encode($row[$i] ?? '', self::JSON_FLAGS);
        }

        return "  {\n" . implode(",\n", $members) . "\n  }";
    }
}
