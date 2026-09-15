<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\FilteredSeries;

/**
 * What a capture-reading query would cost, without running it.
 *
 * "Cost grows with the window" is true but unactionable, and the numbers are already
 * knowable: the files in range, their size, and for a filtered series the number of nfdump
 * runs. The filtered-graph panel shows this beside its Apply button, and any non-interactive
 * caller wants it before committing to a scan it cannot see the progress of.
 */
final readonly class CostEstimate {
    public function __construct(
        public int $files,
        public int $bytes,
        public int $runs,
        public TimeWindow $window,
    ) {}

    /**
     * A single nfdump pass over the window: Flows, Statistics, Sankey.
     *
     * @param list<string> $sources
     */
    public static function forSinglePass(TimeWindow $window, array $sources, string $profile = ''): self {
        $files = NfcapdFiles::list($window->start, $window->end, $sources, $profile);

        return new self(
            files: \count($files),
            bytes: NfcapdFiles::totalSize($files),
            runs: 1,
            window: $window,
        );
    }

    /**
     * A filtered series, which runs one nfdump per time bin per group rather than once over
     * the whole window. The bin width is solved from the requested resolution, so the run
     * count follows from the same arithmetic the builder uses.
     *
     * @param list<string> $sources
     */
    public static function forFilteredSeries(
        TimeWindow $window,
        array $sources,
        int $targetPoints,
        int $groupCount = 1,
        string $profile = '',
    ): self {
        $files = NfcapdFiles::list($window->start, $window->end, $sources, $profile);

        return new self(
            files: \count($files),
            bytes: NfcapdFiles::totalSize($files),
            runs: self::runsForFilteredSeries($window, $targetPoints, $groupCount),
            window: $window,
        );
    }

    /**
     * How many nfdump runs a filtered series needs, without touching the filesystem.
     *
     * Separate from forFilteredSeries() because the graph panel shows this on every render,
     * where walking the capture tree would put a disk scan in front of each frame.
     */
    public static function runsForFilteredSeries(TimeWindow $window, int $targetPoints, int $groupCount = 1): int {
        $groupCount = max(1, $groupCount);
        $step = FilteredSeries::binWidth($window->start, $window->end, $targetPoints, $groupCount);
        $bins = (int) ceil(max(1, $window->duration()) / $step);

        return $bins * $groupCount;
    }

    /**
     * @return array{files: int, bytes: int, runs: int, window_clamped: bool}
     */
    public function toArray(): array {
        return [
            'files' => $this->files,
            'bytes' => $this->bytes,
            'runs' => $this->runs,
            'window_clamped' => $this->window->clamped,
        ];
    }
}
