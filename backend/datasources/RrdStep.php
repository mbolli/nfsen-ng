<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\datasources;

/**
 * Rewrites an RRD at another step, keeping what it holds (#184). rrd_create --source would fill a row the finest
 * archive has no data for from a coarser one, inventing traffic at the edges of a gap, and it keeps rrdtool's stamps
 * while a row here holds the file starting at its stamp. So every row of every archive is resampled here instead:
 * the rates of the finest source archive that reaches back to it, weighted by the time each covers.
 */
final class RrdStep {
    /** A row with more of its time unknown than this stays unknown, as rrdtool's xff. */
    private const float XFF = 0.5;

    /**
     * @param list<string> $definition everything rrd_create takes but --start, for the new step
     */
    public static function rewrite(string $file, int $to, array $definition): bool {
        $source = self::parse(self::dump($file));
        if ($source === null) {
            return false;
        }
        $from = $source['step'];
        // A row holds the file starting at its stamp, the end of its step: the last update moves by one old step
        // less one new one. From the last complete old slot, so the files of a slot in progress are imported again.
        $last = intdiv($source['last'], $from) * $from + $from - $to;

        $tmp = $file . '.step';
        self::remove($tmp);
        if (!rrd_create($tmp, ['--start', (string) $last, ...$definition])) {
            return false;
        }
        $skeleton = self::dump($tmp);
        self::remove($tmp);
        if ($skeleton === null) {
            return false;
        }

        $xml = $file . '.xml';
        $restored = $file . '.restored';
        file_put_contents($xml, self::fill($skeleton, $source, $to));
        self::remove($restored);
        $ok = @rrd_restore($xml, $restored) && rename($restored, $file);
        self::remove($xml);
        self::remove($restored);

        return $ok;
    }

    /**
     * The skeleton's rows filled from the source archives of the same function.
     *
     * @param array{step: int, last: int, archives: list<array{cf: string, width: int, stamps: list<int>, values: list<list<float>>}>} $source
     */
    private static function fill(string $skeleton, array $source, int $to): string {
        $out = '';
        $at = 0;
        while (($open = strpos($skeleton, '<rra>', $at)) !== false) {
            $close = (int) strpos($skeleton, '</rra>', $open) + 6;
            $rra = substr($skeleton, $open, $close - $open);
            $cf = preg_match('#<cf>\s*(\w+)\s*</cf>#', $rra, $c) === 1 ? $c[1] : 'AVERAGE';
            $width = (preg_match('#<pdp_per_row>(\d+)</pdp_per_row>#', $rra, $p) === 1 ? (int) $p[1] : 1) * $to;
            $candidates = array_values(array_filter($source['archives'], static fn (array $a): bool => $a['cf'] === $cf));
            usort($candidates, static fn (array $a, array $b): int => $a['width'] <=> $b['width']);

            $rra = (string) preg_replace_callback(
                '#<!--[^>]*/ (\d+) -->\s*<row>(.*?)</row>#',
                static function (array $m) use ($candidates, $width, $to, $source, $cf): string {
                    // The real time of the row, as the files it holds.
                    $end = (int) $m[1] + $to;
                    $values = self::resample($candidates, $source['step'], $end - $width, $end, $cf === 'MAX', substr_count($m[2], '<v>'));

                    return '<row>' . implode('', array_map(
                        static fn (?float $v): string => '<v>' . ($v === null ? 'NaN' : \sprintf('%.10e', $v)) . '</v>',
                        $values,
                    )) . '</row>';
                },
                $rra,
            );
            $out .= substr($skeleton, $at, $open - $at) . $rra;
            $at = $close;
        }

        return $out . substr($skeleton, $at);
    }

    /**
     * The rates over [$start, $end) of the finest archive that reaches back to $start.
     *
     * @param list<array{cf: string, width: int, stamps: list<int>, values: list<list<float>>}> $archives
     *
     * @return list<?float>
     */
    private static function resample(array $archives, int $step, int $start, int $end, bool $max, int $count): array {
        $none = array_fill(0, $count, null);
        foreach ($archives as $archive) {
            $w = $archive['width'];
            $first = $archive['stamps'][0] ?? null;
            // Row k holds the real time [first + k·w - w + step, first + k·w + step).
            if ($first === null || $first - $w + $step > $start) {
                continue;
            }
            $n = \count($archive['stamps']);
            $k0 = max(0, intdiv($start - ($first - $w + $step), $w));
            $known = array_fill(0, $count, 0);
            $sums = array_fill(0, $count, 0.0);
            $peaks = $none;
            for ($k = $k0; $k < $n; ++$k) {
                $rowStart = $first + $k * $w - $w + $step;
                if ($rowStart >= $end) {
                    break;
                }
                $overlap = min($end, $rowStart + $w) - max($start, $rowStart);
                if ($overlap <= 0) {
                    continue;
                }
                foreach ($archive['values'][$k] as $d => $v) {
                    if (is_nan($v)) {
                        continue;
                    }
                    $known[$d] += $overlap;
                    $sums[$d] += $v * $overlap;
                    $peaks[$d] = max($peaks[$d] ?? $v, $v);
                }
            }
            $values = $none;
            foreach ($known as $d => $seconds) {
                if ($seconds > 0 && $seconds >= ($end - $start) * (1 - self::XFF)) {
                    $values[$d] = $max ? $peaks[$d] : $sums[$d] / $seconds;
                }
            }

            return array_values($values);
        }

        return $none;
    }

    /**
     * @return null|array{step: int, last: int, archives: list<array{cf: string, width: int, stamps: list<int>, values: list<list<float>>}>}
     */
    private static function parse(?string $dump): ?array {
        if ($dump === null
            || preg_match('#<step>(\d+)</step>#', $dump, $s) !== 1
            || preg_match('#<lastupdate>(\d+)</lastupdate>#', $dump, $l) !== 1) {
            return null;
        }
        $step = (int) $s[1];
        $archives = [];
        $at = 0;
        while (($open = strpos($dump, '<rra>', $at)) !== false) {
            $close = (int) strpos($dump, '</rra>', $open);
            $rra = substr($dump, $open, $close - $open);
            $at = $close;
            $cf = preg_match('#<cf>\s*(\w+)\s*</cf>#', $rra, $c) === 1 ? $c[1] : 'AVERAGE';
            $pdp = preg_match('#<pdp_per_row>(\d+)</pdp_per_row>#', $rra, $p) === 1 ? (int) $p[1] : 1;
            preg_match_all('#/ (\d+) -->\s*<row>(.*?)</row>#', $rra, $rows, PREG_SET_ORDER);
            $stamps = [];
            $values = [];
            foreach ($rows as $row) {
                $stamps[] = (int) $row[1];
                preg_match_all('#<v>\s*([^<]+?)\s*</v>#', $row[2], $v);
                $values[] = array_map(static fn (string $x): float => is_numeric($x) ? (float) $x : NAN, $v[1]);
            }
            $archives[] = ['cf' => $cf, 'width' => $pdp * $step, 'stamps' => $stamps, 'values' => $values];
        }

        return ['step' => $step, 'last' => (int) $l[1], 'archives' => $archives];
    }

    private static function dump(string $file): ?string {
        $xml = $file . '.dump';
        exec('rrdtool dump ' . escapeshellarg($file) . ' ' . escapeshellarg($xml) . ' 2>/dev/null', $out, $code);
        $dump = $code === 0 && is_file($xml) ? file_get_contents($xml) : false;
        self::remove($xml);

        return $dump === false ? null : $dump;
    }

    private static function remove(string $file): void {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
