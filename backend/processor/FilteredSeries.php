<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\datasources\Datasource;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

/**
 * Builds a filter-aware time series by re-reading the nfcapd files behind a window.
 *
 * The RRD/VictoriaMetrics datasources store pre-aggregated flows/packets/bytes per
 * 5-minute slot, so an nfdump filter cannot be applied to them after the fact (#166).
 * The only way to plot "traffic matching this filter over time" is to go back to the
 * capture files, which is exactly what Import::writePortData() already does for the
 * per-port RRDs, just with a filter hardcoded to `dst port N`. This generalises that
 * to an arbitrary filter and assembles a GraphData series instead of an RRD write.
 *
 * Cost model: one nfdump invocation per *bin* (not per file), so the number of
 * processes is bounded by the requested resolution rather than by the window width.
 * The bytes read off disk still scale with the window: the same bytes the Flows tab
 * already reads for the same range in a single pass.
 *
 * Bins run side by side, one per slot the build holds; after each bin a worker gives its slot
 * back to a waiting user query, or to background work that needs it (see ceiling()).
 *
 * @phpstan-type Job array{bin: int, group: int, sources: list<string>, files: list<NfcapdFile>}
 * @phpstan-type Rows array<array<string, mixed>>
 *
 * @phpstan-import-type GraphData from Datasource
 * @phpstan-import-type NfcapdFile from NfcapdFiles
 * @phpstan-import-type Scope from NfdumpSlots
 */
final class FilteredSeries {
    /** The protocol split the RRD schema and the chart already use. */
    public const ALL_PROTOCOLS = ['tcp', 'udp', 'icmp', 'other'];

    /** nfcapd rotates every 5 minutes, so no bin can be narrower than that. */
    public const MIN_BIN = 300;

    /**
     * Upper bound on nfdump invocations for one build. Each bin costs a process, and
     * 'sources' display multiplies that by the source count; without a ceiling a wide
     * window at high resolution would fork thousands of times.
     */
    public const MAX_RUNS = 600;

    /** @var array<int, null|Rows> job => nfdump rows, null for a gap; no entry until the job ran */
    private array $results = [];

    /** @var list<int> job => jobs from there on that have files to read */
    private array $workFrom = [];

    /** The next job no worker has taken yet. Jobs are taken in order. */
    private int $next = 0;

    private int $done = 0;

    /** Workers holding a slot of this build right now. */
    private int $workers = 0;

    /** Workers started in coroutines of their own, and how many of those have ended. */
    private int $spawned = 0;

    private int $reaped = 0;

    private bool $cancelled = false;

    private ?\Throwable $failure = null;

    /** Slots worth holding at once: one per job with files, at most the process limit. */
    private readonly int $limit;

    /** Where spawned workers report their end; null outside a coroutine, where nothing is spawned. */
    private readonly ?Channel $exits;

    /** @var null|\Closure(int, int): void */
    private readonly ?\Closure $onProgress;

    /** @var null|\Closure(): bool */
    private readonly ?\Closure $shouldCancel;

    /**
     * @param list<Job>                     $jobs         in bin order, the groups of one bin together
     * @param Scope                         $scope        the caller's, whose class, wait and budget the slots follow
     * @param null|callable(int, int): void $onProgress
     * @param null|callable(): bool         $shouldCancel
     */
    private function __construct(
        private readonly array $jobs,
        private readonly string $filter,
        private readonly string $profile,
        private readonly string $handle,
        private readonly array $scope,
        ?callable $onProgress,
        ?callable $shouldCancel,
    ) {
        $this->onProgress = $onProgress === null ? null : $onProgress(...);
        $this->shouldCancel = $shouldCancel === null ? null : $shouldCancel(...);

        $withFiles = \count(array_filter($jobs, static fn (array $job): bool => $job['files'] !== []));
        $left = $withFiles;
        foreach ($jobs as $job) {
            $this->workFrom[] = $left;
            $left -= $job['files'] === [] ? 0 : 1;
        }

        $this->limit = max(1, min($withFiles, NfdumpSlots::max()));
        $this->exits = Coroutine::getCid() > 0 ? new Channel($this->limit) : null;
    }

    /**
     * Build the series.
     *
     * Bins run concurrently when called from a coroutine, one after another otherwise.
     * $onProgress and $shouldCancel may be called from any of the build's coroutines.
     *
     * @param list<string>                  $sources      already resolved (no 'any' sentinel)
     * @param list<string>                  $protocols    ['any'], or a subset of tcp/udp/icmp/other
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
        array $protocols = ['any'],
        string $unit = 'flows',
        string $display = 'protocols',
        int $targetPoints = 150,
        string $profile = '',
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
        string $handle = 'default',
    ): array {
        // Floor first, then list. Bins are laid out from the floored start, so listing from
        // the raw one dropped the capture covering the first partial bin and under-reported
        // it. Rrd::get_graph_data() floors the same way, so this also keeps Stored and
        // Filtered covering the same window.
        $binStart = $start - ($start % self::MIN_BIN);
        $files = NfcapdFiles::list($binStart, $end, $sources, $profile);

        if ($files === []) {
            throw new \Exception('No nfcapd files found in the selected time range.');
        }

        $step = self::binWidth($binStart, $end, $targetPoints, $display === 'sources' ? \count($sources) : 1);

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

        // Mirror how the datasources read the protocol selection: for the protocols display
        // it chooses which series exist, for the sources display it narrows the counter that
        // every source series is built from (Rrd::get_graph_data() indexes $protocols[0]).
        $selected = self::normalizeProtocolSelection($protocols);
        $seriesProtocols = ($selected === ['any']) ? self::ALL_PROTOCOLS : $selected;
        $sourceProtocol = ($selected[0] === 'any') ? null : $selected[0];

        $legend = [];
        if ($display === 'sources') {
            foreach ($sources as $source) {
                $legend[] = implode('_', [$source, self::legendUnit($unit), $sourceProtocol ?? 'any']);
            }
        } else {
            foreach ($seriesProtocols as $protocol) {
                $legend[] = implode('_', array_filter([$protocol, self::legendUnit($unit), $sources[0] ?? '']));
            }
        }

        // Walk every bin in the range, not only the ones that have captures. Omitting an
        // empty bin leaves no row at that timestamp at all, and ECharts draws a straight
        // line across it, so a collection outage looked like steady traffic, while the
        // same window in Stored mode shows a real gap.
        $seriesCount = \count($legend);
        $binTimestamps = [];
        for ($ts = $binStart; $ts <= $end; $ts += $step) {
            $binTimestamps[] = $ts;
        }

        $jobs = [];
        foreach ($binTimestamps as $binTs) {
            foreach ($groups as $groupIndex => $group) {
                $groupFiles = [];
                foreach ($group as $source) {
                    foreach ($bins[$binTs][$source] ?? [] as $file) {
                        $groupFiles[] = $file;
                    }
                }
                $jobs[] = ['bin' => $binTs, 'group' => $groupIndex, 'sources' => $group, 'files' => $groupFiles];
            }
        }

        $results = new self($jobs, $filter, $profile, $handle, NfdumpSlots::scope(), $onProgress, $shouldCancel)->runAll();

        $data = [];
        foreach ($jobs as $index => $job) {
            if (!\array_key_exists($index, $results)) {
                // Cancelled: the series ends before the first job that did not finish, and a bin
                // is kept only when every one of its groups ran.
                if ($job['group'] > 0) {
                    unset($data[$job['bin']]);
                }

                break;
            }

            // Start every bin as a gap; only counters nfdump actually reports overwrite it.
            $row = $data[$job['bin']] ?? array_fill(0, $seriesCount, null);
            // null (no capture, or nfdump failed) draws as a gap: a 0 would claim "no traffic"
            // for a truncated capture or a rejected invocation.
            $stats = $results[$index];

            if ($stats !== null && $display === 'sources') {
                $value = $sourceProtocol === null
                    ? self::sumAll($stats, $unit)
                    : self::sumProtocol($stats, $sourceProtocol, $unit);
                $row[$job['group']] = self::rate($value, $step, $unit);
            } elseif ($stats !== null) {
                foreach ($seriesProtocols as $i => $protocol) {
                    $row[$i] = self::rate(self::sumProtocol($stats, $protocol, $unit), $step, $unit);
                }
            }

            // array_values() keeps this a list for the GraphData contract: $row is seeded
            // by array_fill() and only ever has existing indices overwritten.
            $data[$job['bin']] = array_values($row);
        }

        return self::assemble($binStart, $end, $step, $legend, $data);
    }

    /**
     * Reduce a raw protocol selection to ['any'] or an ordered subset of ALL_PROTOCOLS.
     *
     * The signal is client-writable, so entries can be anything; 'any' anywhere means no
     * protocol restriction, and an empty or unrecognised selection means the same.
     *
     * @param list<string> $protocols
     *
     * @return non-empty-list<string>
     */
    public static function normalizeProtocolSelection(array $protocols): array {
        $clean = array_values(array_filter(
            array_map(static fn (string $p): string => strtolower(trim($p)), $protocols),
            static fn (string $p): bool => \in_array($p, self::ALL_PROTOCOLS, true)
        ));

        if ($clean === [] || \in_array('any', array_map('strtolower', $protocols), true)) {
            return ['any'];
        }

        // Keep the canonical order so the legend is stable regardless of click order.
        $ordered = [];
        foreach (self::ALL_PROTOCOLS as $protocol) {
            if (\in_array($protocol, $clean, true)) {
                $ordered[] = $protocol;
            }
        }

        return $ordered === [] ? ['any'] : $ordered;
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

        // Solved, not stepped: graph_sources is client-writable, and a loop could not end once
        // $groupCount alone exceeds MAX_RUNS.
        $maxBins = max(1, intdiv(self::MAX_RUNS, max(1, $groupCount)));
        $needed = (int) (ceil($span / $maxBins / self::MIN_BIN) * self::MIN_BIN);

        return max($step, $needed, self::MIN_BIN);
    }

    /**
     * Runs every job and returns what each gave, keyed by job. A cancelled build returns the
     * jobs that finished; build() keeps them up to the first that did not.
     *
     * @return array<int, null|Rows>
     *
     * @throws \Throwable what a run threw other than an nfdump failure, after every worker ended
     */
    private function runAll(): array {
        if ($this->scope['held']) {
            // The caller already holds a slot for these runs: they share it, one after another.
            while (($job = $this->claim()) !== null) {
                $this->finish($job);
            }

            return $this->results;
        }

        while (true) {
            // Bins without a capture need no nfdump, so no slot either.
            while (($this->jobs[$this->next]['files'] ?? null) === [] && ($job = $this->claim()) !== null) {
                $this->complete($job, null);
            }
            if ($this->failure !== null || $this->next >= \count($this->jobs) || $this->cancelRequested()) {
                break;
            }

            try {
                $granted = NfdumpSlots::acquireMany(
                    $this->exits === null ? 1 : min($this->ceiling(), $this->workFrom[$this->next]),
                    $this->scope['class'],
                    NfdumpSlots::waitFor($this->scope),
                );
            } catch (\RuntimeException $e) {
                if (!NfdumpSlots::timedOut($e)) {
                    throw $e;
                }
                // As when every run took its own slot: the bin that got none in time is a gap,
                // and the next one tries again.
                Debug::getInstance()->log('FilteredSeries: bin failed: ' . $e->getMessage(), LOG_WARNING);
                if (($job = $this->claim()) !== null) {
                    $this->complete($job, null);
                }

                continue;
            }

            // One worker per slot. This coroutine runs the first itself, so a build always
            // makes progress even when no coroutine can be started.
            ++$this->workers;
            $this->spawn($granted - 1);
            $this->work(false);
            while ($this->reaped < $this->spawned) {
                $this->exits?->pop();
                ++$this->reaped;
            }
            // Jobs left here mean every worker handed its slot to a user query: queue behind it.
            // Background work never takes the last worker, see ceiling().
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->results;
    }

    /**
     * One worker: takes jobs in order and runs them in the slot it was started with, until
     * none is left, the build stops, or handsOver() gives the slot away.
     */
    private function work(bool $spawned): void {
        try {
            NfdumpSlots::runInHeldSlot($this->scope['class'], function (): void {
                while (($job = $this->claim()) !== null) {
                    $this->grow();
                    $this->finish($job);
                    if ($this->handsOver()) {
                        return;
                    }
                }
            });
        } catch (\Throwable $e) {
            // Thrown out of a coroutine it would end the whole OpenSwoole worker; runAll() rethrows it.
            $this->failure ??= $e;
        } finally {
            NfdumpSlots::release($this->scope['class']);
            --$this->workers;
            if ($spawned) {
                $this->exits?->push(true);
            }
        }
    }

    /** Starts $n workers in coroutines of their own, one per slot already taken for them. */
    private function spawn(int $n): void {
        for ($i = 0; $i < $n; ++$i) {
            ++$this->workers;
            ++$this->spawned;
            if (Coroutine::create(fn () => $this->work(true)) === false) {
                --$this->workers;
                --$this->spawned;
                NfdumpSlots::release($this->scope['class']);
            }
        }
    }

    /** Takes slots that freed up since the pool started, while nobody waits for them. */
    private function grow(): void {
        if ($this->exits === null) {
            return;
        }

        $room = min($this->ceiling() - $this->workers, $this->workFrom[$this->next] ?? 0);
        $free = $room > 0 ? NfdumpSlots::available($this->scope['class']) : 0;
        if ($free > 0) {
            $this->spawn(NfdumpSlots::acquireMany(min($room, $free), $this->scope['class'], 0.0));
        }
    }

    /**
     * Whether this worker ends and gives its slot back: a user query waits with none free, or
     * the pool holds more than ceiling() allows.
     */
    private function handsOver(): bool {
        return $this->exits !== null
            && (NfdumpSlots::waiting(NfdumpSlots::INTERACTIVE) > NfdumpSlots::grantable(NfdumpSlots::INTERACTIVE)
                || $this->workers > $this->ceiling());
    }

    /**
     * Slots the pool may hold now: all it can use, less what background work needs to start (two
     * free) or to go on (one free), and never fewer than one.
     */
    private function ceiling(): int {
        if ($this->scope['class'] !== NfdumpSlots::INTERACTIVE) {
            return $this->limit;
        }

        $max = NfdumpSlots::max();
        $others = NfdumpSlots::inUse() - $this->workers;
        $background = NfdumpSlots::inUse(NfdumpSlots::BACKGROUND);
        if (NfdumpSlots::waiting(NfdumpSlots::BACKGROUND) > 0
            && $background < NfdumpSlots::backgroundMax()
            && $max - 2 - $others >= 1) {
            return min($this->limit, $max - 2 - $others);
        }
        if ($background > 0) {
            // One slot stays free, so its next run starts without queueing.
            return max(1, min($this->limit, $max - 1 - $others));
        }

        return $this->limit;
    }

    /** The next job to run, or null once none is left or the build stopped. */
    private function claim(): ?int {
        if ($this->failure !== null || $this->next >= \count($this->jobs) || $this->cancelRequested()) {
            return null;
        }

        return $this->next++;
    }

    private function cancelRequested(): bool {
        if (!$this->cancelled && $this->shouldCancel !== null && ($this->shouldCancel)()) {
            $this->cancelled = true;
            Debug::getInstance()->log('FilteredSeries: cancelled after ' . $this->done . '/' . \count($this->jobs) . ' bins', LOG_INFO);
        }

        return $this->cancelled;
    }

    /** @param null|Rows $rows */
    private function complete(int $job, ?array $rows): void {
        $this->results[$job] = $rows;
        ++$this->done;
        if ($this->onProgress !== null) {
            ($this->onProgress)($this->done, \count($this->jobs));
        }
    }

    /** Runs $job and records it, unless a Kill ended it: build() then ends the partial series before it. */
    private function finish(int $job): void {
        $rows = $this->runJob($job);
        if ($rows === null && $this->jobs[$job]['files'] !== [] && $this->cancelRequested()) {
            return;
        }
        $this->complete($job, $rows);
    }

    /** @return null|Rows */
    private function runJob(int $job): ?array {
        $files = $this->jobs[$job]['files'];

        return $files === [] ? null : self::runBin($this->jobs[$job]['sources'], $files, $this->filter, $this->profile, $this->handle);
    }

    /**
     * Run one nfdump per-protocol statistic over the files of a single bin.
     *
     * `-s proto` (no orderby) is the generic equivalent of what Import::writePortData()
     * does with `-s dstport:p`: nfdump applies the filter, then reports flows/packets/bytes
     * grouped by transport protocol: exactly the tcp/udp/icmp/other split the RRD schema
     * and the chart already use. `:p` would be redundant here (splitting proto by proto).
     *
     * `-n 0` is load-bearing: nfdump defaults `-n` to **10** for `-s` statistics, so
     * without it a bin would silently report only the ten largest protocol rows.
     *
     * @param list<string>     $group
     * @param list<NfcapdFile> $files
     *
     * @return null|array<array<string, mixed>> decoded nfdump rows, or null when the
     *                                          invocation failed, which is a gap, not a zero
     */
    private static function runBin(array $group, array $files, string $filter, string $profile, string $handle = 'default'): ?array {
        // Only the sources that actually have a capture in this bin. nfdump reads the same
        // relative path from every -M directory and aborts one with "stat() error …: File not
        // found!" when its file has not been rotated into place yet (the same case the import
        // guards, #173), which failed the bin and drew it as a gap rather than real traffic.
        $present = array_values(array_intersect($group, array_unique(array_column($files, 'source'))));
        $sources = $present === [] ? $group : $present;

        if (\count($sources) !== \count($group)) {
            Debug::getInstance()->log(
                'Filtered bin over ' . implode(',', $sources) . ' only, no capture (yet) for '
                . implode(',', array_diff($group, $sources)),
                LOG_DEBUG,
            );
        }

        $relPaths = array_column(
            array_filter($files, static fn (array $f): bool => \in_array($f['source'], $sources, true)),
            'relPath'
        );
        sort($relPaths);
        $first = $relPaths[0];
        $last = $relPaths[\count($relPaths) - 1];

        // A processor per bin, since bins run concurrently. They all run under the build's
        // handle, so cancelling the build kills every bin in flight.
        $nfdump = new Config::$processorClass();
        $nfdump->setQueryHandle($handle);
        $nfdump->setProfile($profile);
        $nfdump->setOption('-M', implode(':', $sources));

        // -r for one file, -R only for a real range. nfdump reads a single-path -R as a
        // *prefix* ("read all files beginning with file"), which happens to select exactly
        // one file only because every nfcapd name is the same length; a site whose captures
        // carry a suffix would silently pull extra files into the bin. -r is the unambiguous
        // form for one file, and is what the import path already uses.
        if ($first === $last) {
            $nfdump->setOption('-r', $first);
        } else {
            $nfdump->setOption('-R', $first . ':' . $last);
        }

        $nfdump->setOption('-s', 'proto');
        $nfdump->setOption('-n', 0);
        $nfdump->setOption('-o', 'csv');
        $nfdump->setFilter($filter);

        try {
            $result = $nfdump->execute();
        } catch (\Exception $e) {
            // A single unreadable bin must not sink the whole graph. null, not []: the
            // caller renders null as a gap, while [] is the legitimate "filter matched
            // nothing here" answer and must stay a zero.
            Debug::getInstance()->log('FilteredSeries: bin failed: ' . $e->getMessage(), LOG_WARNING);

            return null;
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

        foreach ($rows as $row) {
            // All four keys, exactly as Import::writePortData() checks them. nfdump
            // versions before 1.7.8 still print a trailing Summary block in CSV stat
            // output; it has a value in the 'pr' column but no counters, and keying on
            // 'pr' alone lets its numbers land in whichever series matched.
            if (!\is_array($row) || !isset($row['pr'], $row['fl'], $row['ipkt'], $row['ibyt'])) {
                continue;
            }
            $rowProto = strtolower(trim((string) $row['pr']));
            if ($rowProto === 'pr') {
                continue; // header echoed into the body
            }

            // The same bucketing the import writes, so the two graph modes agree: ICMPv6 is
            // icmp in both, and everything nfdump names beyond tcp/udp/icmp is other.
            if (Import::protocolBucket($rowProto) !== $protocol) {
                continue;
            }

            $sum += self::counter($row, $unit);
        }

        // No rows for this protocol is 0, the same as rows that summed to 0: nfdump read the
        // bin either way, so the traffic really was zero. A gap means nfdump could not read
        // the bin at all, which runBin() reports as null before this is ever called.
        return $sum;
    }

    /**
     * Sum a counter across every protocol row.
     *
     * @param array<array<string, mixed>> $rows
     */
    private static function sumAll(array $rows, string $unit): float {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!\is_array($row)
                || !isset($row['pr'], $row['fl'], $row['ipkt'], $row['ibyt'])
                || strtolower(trim((string) $row['pr'])) === 'pr') {
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
     * orderby, so `ipkt`/`ibyt` is what arrives, but accepting all three costs nothing
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
