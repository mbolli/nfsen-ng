<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\datasources\Datasource;

/**
 * Builds a filter-aware time series by re-reading the nfcapd files behind a window.
 *
 * The RRD/VictoriaMetrics datasources store pre-aggregated flows/packets/bytes per
 * 5-minute slot, so an nfdump filter cannot be applied to them after the fact (#166).
 * The only way to plot "traffic matching this filter over time" is to go back to the
 * capture files — which is exactly what Import::writePortData() already does for the
 * per-port RRDs, just with a filter hardcoded to `dst port N`. This generalises that
 * to an arbitrary filter and assembles a GraphData series instead of an RRD write.
 *
 * Cost model: one nfdump invocation per *bin* (not per file), so the number of
 * processes is bounded by the requested resolution rather than by the window width.
 * The bytes read off disk still scale with the window — the same bytes the Flows tab
 * already reads for the same range in a single pass.
 *
 * @phpstan-import-type GraphData from Datasource
 * @phpstan-import-type NfcapdFile from NfcapdFiles
 */
final class FilteredSeries {
    /** nfcapd rotates every 5 minutes, so no bin can be narrower than that. */
    public const MIN_BIN = 300;

    /**
     * Upper bound on nfdump invocations for one build. Each bin costs a process, and
     * 'sources' display multiplies that by the source count — without a ceiling a wide
     * window at high resolution would fork thousands of times.
     */
    public const MAX_RUNS = 600;

    /**
     * Build the series.
     *
     * @param list<string>                  $sources      already resolved (no 'any' sentinel)
     * @param string                        $unit         flows|packets|bytes|bits
     * @param string                        $display      protocols|sources
     * @param null|callable(int, int): void $onProgress   (done, total) after each bin
     * @param null|callable(): bool         $shouldCancel returning true aborts the run
     *
     * @return GraphData
     *
     * @throws \Exception when the range holds no capture files at all
     */
    public static function build(
        int $start,
        int $end,
        array $sources,
        string $filter,
        string $unit = 'flows',
        string $display = 'protocols',
        int $targetPoints = 150,
        string $profile = '',
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
    ): array {
        $d = Debug::getInstance();
        $files = NfcapdFiles::list($start, $end, $sources, $profile);

        if ($files === []) {
            throw new \Exception('No nfcapd files found in the selected time range.');
        }

        $step = self::binWidth($start, $end, $targetPoints, $display === 'sources' ? \count($sources) : 1);
        $binStart = $start - ($start % self::MIN_BIN);

        // 'sources' needs a per-source number, so each source is queried separately;
        // every other display can let nfdump merge the sources itself via -M a:b:c.
        $groups = ($display === 'sources') ? array_map(static fn (string $s) => [$s], $sources) : [$sources];

        /** @var array<int, array<string, list<NfcapdFile>>> $bins bin ts => group key => files */
        $bins = [];
        foreach ($files as $file) {
            $bin = $binStart + (int) (floor(($file['ts'] - $binStart) / $step) * $step);
            $bins[$bin][$file['source']][] = $file;
        }
        ksort($bins);

        $protocols = ['tcp', 'udp', 'icmp', 'other'];
        $legend = [];
        if ($display === 'sources') {
            foreach ($sources as $source) {
                $legend[] = implode('_', [$source, self::legendUnit($unit), 'any']);
            }
        } else {
            foreach ($protocols as $protocol) {
                $legend[] = implode('_', array_filter([$protocol, self::legendUnit($unit), $sources[0] ?? '']));
            }
        }

        $seriesCount = \count($legend);
        $total = \count($bins) * \count($groups);
        $done = 0;
        $data = [];

        foreach ($bins as $binTs => $filesBySource) {
            // Start every bin as a gap; only counters nfdump actually reports overwrite it.
            $row = array_fill(0, $seriesCount, null);

            foreach ($groups as $groupIndex => $group) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $d->log('FilteredSeries: cancelled after ' . $done . '/' . $total . ' bins', LOG_INFO);

                    return self::assemble($binStart, $end, $step, $legend, $data);
                }

                $groupFiles = [];
                foreach ($group as $source) {
                    foreach ($filesBySource[$source] ?? [] as $file) {
                        $groupFiles[] = $file;
                    }
                }

                ++$done;
                if ($groupFiles === []) {
                    // No capture covers this bin for this group — leave the gap.
                    if ($onProgress !== null) {
                        $onProgress($done, $total);
                    }

                    continue;
                }

                $stats = self::runBin($group, $groupFiles, $filter, $profile);

                if ($display === 'sources') {
                    $row[$groupIndex] = self::rate(self::sumAll($stats, $unit), $step, $unit);
                } else {
                    foreach ($protocols as $i => $protocol) {
                        $row[$i] = self::rate(self::sumProtocol($stats, $protocol, $unit), $step, $unit);
                    }
                }

                if ($onProgress !== null) {
                    $onProgress($done, $total);
                }
            }

            // array_values() keeps this a list for the GraphData contract: $row is seeded
            // by array_fill() and only ever has existing indices overwritten, so the order
            // is already correct — this just makes the list-ness provable.
            $data[$binTs] = array_values($row);
        }

        return self::assemble($binStart, $end, $step, $legend, $data);
    }

    /**
     * Bin width in seconds: at least MIN_BIN, always a whole multiple of it so bins line
     * up with nfcapd rotation, and never so fine that the run would exceed MAX_RUNS.
     */
    public static function binWidth(int $start, int $end, int $targetPoints, int $groupCount = 1): int {
        $span = max(1, $end - $start);
        $points = max(1, $targetPoints);
        $groupCount = max(1, $groupCount);

        $step = (int) (ceil($span / $points / self::MIN_BIN) * self::MIN_BIN);
        $step = max(self::MIN_BIN, $step);

        // Widen until the projected process count fits under the ceiling.
        while ((int) ceil($span / $step) * $groupCount > self::MAX_RUNS) {
            $step += self::MIN_BIN;
        }

        return $step;
    }

    /**
     * Run one nfdump per-protocol statistic over the files of a single bin.
     *
     * `-s proto` (no orderby) is the generic equivalent of what Import::writePortData()
     * does with `-s dstport:p`: nfdump applies the filter, then reports flows/packets/bytes
     * grouped by transport protocol — exactly the tcp/udp/icmp/other split the RRD schema
     * and the chart already use. `:p` would be redundant here (splitting proto by proto).
     *
     * `-n 0` is load-bearing: nfdump defaults `-n` to **10** for `-s` statistics, so
     * without it a bin would silently report only the ten largest protocol rows.
     *
     * @param list<string>     $group
     * @param list<NfcapdFile> $files
     *
     * @return array<array<string, mixed>> decoded nfdump rows
     */
    private static function runBin(array $group, array $files, string $filter, string $profile): array {
        $relPaths = array_column($files, 'relPath');
        sort($relPaths);
        $first = $relPaths[0];
        $last = $relPaths[\count($relPaths) - 1];

        $nfdump = new Config::$processorClass();
        $nfdump->setProfile($profile);
        $nfdump->setOption('-M', implode(':', $group));
        $nfdump->setOption('-R', $first === $last ? $first : $first . ':' . $last);
        $nfdump->setOption('-s', 'proto');
        $nfdump->setOption('-n', 0);
        $nfdump->setOption('-o', 'csv');
        $nfdump->setFilter($filter);

        try {
            $result = $nfdump->execute();
        } catch (\Exception $e) {
            // A single unreadable bin must not sink the whole graph — leave it a gap.
            Debug::getInstance()->log('FilteredSeries: bin failed: ' . $e->getMessage(), LOG_WARNING);

            return [];
        }

        return $result['decoded'] ?? [];
    }

    /**
     * Sum one protocol's counter across nfdump's stat rows.
     *
     * @param array<array<string, mixed>> $rows
     */
    private static function sumProtocol(array $rows, string $protocol, string $unit): float {
        $sum = 0.0;
        $matchedAny = false;

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['pr'])) {
                continue;
            }
            $rowProto = strtolower(trim((string) $row['pr']));
            if ($rowProto === 'pr') {
                continue; // header echoed into the body
            }

            $isKnown = \in_array($rowProto, ['tcp', 'udp', 'icmp'], true);
            $matches = ($protocol === 'other') ? !$isKnown : $rowProto === $protocol;
            if (!$matches) {
                continue;
            }

            $matchedAny = true;
            $sum += self::counter($row, $unit);
        }

        // Distinguish "nfdump reported 0 for this protocol" from "nfdump reported nothing":
        // both are 0 here, and 0 is the honest answer for a bin nfdump did read.
        return $matchedAny ? $sum : 0.0;
    }

    /**
     * Sum a counter across every protocol row.
     *
     * @param array<array<string, mixed>> $rows
     */
    private static function sumAll(array $rows, string $unit): float {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['pr']) || strtolower(trim((string) $row['pr'])) === 'pr') {
                continue;
            }
            $sum += self::counter($row, $unit);
        }

        return $sum;
    }

    /**
     * Pull the requested counter out of one nfdump stat row.
     *
     * nfdump names the packet/byte columns after the ordering direction: the default
     * `flows` orderby is an IN ordering and yields `ipkt`/`ibyt`, while an INOUT orderby
     * (`-s proto/bytes`) yields `pkt`/`byt` and an OUT one `opkt`/`obyt`. We never pass an
     * orderby, so `ipkt`/`ibyt` is what arrives — but accepting all three costs nothing
     * and keeps this working if the invocation ever grows one.
     *
     * @param array<string, mixed> $row
     */
    private static function counter(array $row, string $unit): float {
        $keys = match ($unit) {
            'packets' => ['ipkt', 'pkt', 'opkt'],
            'bytes', 'bits' => ['ibyt', 'byt', 'obyt'],
            default => ['fl'],
        };

        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return 0.0;
    }

    /**
     * Convert a per-bin total into the per-second rate the chart expects.
     *
     * The RRD datasource stores ABSOLUTE data sources and exports them via AVERAGE, so
     * every existing series is already a rate (the chart labels its axis "FLOWS/s",
     * "bits/s", …). Bits are bytes×8, matching Rrd::get_graph_data()'s useBits handling.
     */
    private static function rate(float $total, int $step, string $unit): float {
        $value = ($unit === 'bits') ? $total * 8 : $total;

        return $value / max(1, $step);
    }

    /** RRD spells the traffic series 'traffic' regardless of bits/bytes; mirror its legend wording. */
    private static function legendUnit(string $unit): string {
        return \in_array($unit, ['bits', 'bytes'], true) ? 'bytes' : $unit;
    }

    /**
     * @param list<string>                 $legend
     * @param array<int, list<null|float>> $data
     *
     * @return GraphData
     */
    private static function assemble(int $start, int $end, int $step, array $legend, array $data): array {
        ksort($data);

        return [
            'start' => $start,
            'end' => $end,
            'step' => $step,
            'legend' => $legend,
            'data' => $data,
        ];
    }
}
