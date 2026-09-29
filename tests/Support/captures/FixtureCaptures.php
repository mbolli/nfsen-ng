<?php

declare(strict_types=1);

namespace Tests\Support\captures;

/**
 * Capture trees of synthetic NetFlow v5 traffic, and v9 with out counters, written by a real nfcapd.
 *
 * A seeded generator sends every flow of every 5-minute file to one nfcapd, tagged with the
 * source as engine type and the file as engine id, and nfdump then splits the collected flows
 * into `<root>/<profile>/<source>/Y/m/d/nfcapd.YmdHi`. Keys follow Zipf distributions over
 * fixed pools, so the same hosts, ports and ASNs lead in every file, the way real traffic does:
 * about half the flows are the reverse of a request, and a few are ICMP.
 */
final class FixtureCaptures {
    public const string BIN = '/usr/local/nfdump/bin';

    /** nfcapd's sys_uptime in the headers, in ms: every flow starts well after the boot. */
    private const int UPTIME_MS = 1_000_000_000;

    private const int RECORDS_PER_PACKET = 30;

    /**
     * NetFlow v9 template 256: the fields of a v5 record, then out packets and bytes, engine type and id.
     *
     * @var list<array{int, int}> field type and length
     */
    private const array V9_FIELDS = [
        [8, 4], [12, 4], [15, 4], [10, 2], [14, 2], [2, 4], [1, 4], [22, 4], [21, 4], [7, 2], [11, 2],
        [6, 1], [4, 1], [5, 1], [16, 2], [17, 2], [9, 1], [13, 1], [24, 4], [23, 4], [38, 1], [39, 1],
    ];

    /** @var list<array{int, int}> destination port and protocol, most used first */
    private const array SERVICES = [
        [443, 6], [80, 6], [53, 17], [443, 17], [22, 6], [123, 17], [25, 6], [993, 6], [3389, 6], [445, 6],
        [8080, 6], [5060, 17], [1194, 17], [853, 6], [587, 6], [3306, 6], [5432, 6], [6881, 17], [51820, 17],
        [8443, 6], [161, 17], [514, 17], [389, 6], [636, 6],
    ];

    /** @var list<int> TCP flag sets of finished, reset and running connections */
    private const array TCP_FLAGS = [0x1B, 0x1B, 0x1B, 0x1A, 0x18, 0x14, 0x12, 0x02, 0x10];

    public static function available(string $bin = self::BIN): bool {
        return is_executable($bin . '/nfcapd') && is_executable($bin . '/nfdump');
    }

    /**
     * Writes $files consecutive 5-minute files per source from $start (a multiple of 300, file
     * names in UTC) with about $flowsPerFile flows each, $outPercent of them as v9 with out
     * counters. Returns the flows nfcapd stored.
     *
     * @param list<string> $sources at most 255
     *
     * @throws \RuntimeException when nfcapd or nfdump fails
     */
    public static function build(string $root, string $profile, array $sources, int $start, int $files, int $flowsPerFile, int $seed = 1, string $bin = self::BIN, int $outPercent = 0): int {
        if ($files < 1 || $files > 255 || \count($sources) > 255 || $start % 300 !== 0) {
            throw new \InvalidArgumentException('1 to 255 files and sources, from a multiple of 300.');
        }
        $spool = $root . '/.spool-' . bin2hex(random_bytes(4));
        mkdir($spool, 0o777, true);

        try {
            self::collect($spool, $bin, static function ($socket) use ($sources, $start, $files, $flowsPerFile, $seed, $outPercent): void {
                foreach ($sources as $sourceIndex => $source) {
                    for ($file = 0; $file < $files; ++$file) {
                        srand($seed * 1_000_003 + $sourceIndex * 257 + $file);
                        self::sendFile($socket, $sourceIndex, $file, $start + $file * 300, $flowsPerFile, $outPercent);
                    }
                }
            });

            $stored = 0;
            foreach ($sources as $sourceIndex => $source) {
                for ($file = 0; $file < $files; ++$file) {
                    $ts = $start + $file * 300;
                    $dir = $root . '/' . $profile . '/' . $source . '/' . gmdate('Y/m/d', $ts);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0o777, true);
                    }
                    $target = $dir . '/nfcapd.' . gmdate('YmdHi', $ts);
                    self::run([$bin . '/nfdump', '-R', $spool, '-z=lz4', '-w', $target, '--', "engine-type {$sourceIndex} and engine-id {$file}"]);
                    $stored += self::flows($target, $bin);
                }
            }

            return $stored;
        } finally {
            array_map(unlink(...), glob($spool . '/*') ?: []);
            rmdir($spool);
        }
    }

    /** Copies a tree build() wrote. */
    public static function copy(string $from, string $to): void {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $target = $to . substr($entry->getPathname(), \strlen($from));
            $entry->isDir() ? mkdir($target, 0o777, true) : copy($entry->getPathname(), $target);
        }
    }

    /** The flows one capture file holds. */
    public static function flows(string $file, string $bin = self::BIN): int {
        return preg_match('/^flows:\s*(\d+)/mi', self::run([$bin . '/nfdump', '-r', $file, '-I']), $m) === 1 ? (int) $m[1] : 0;
    }

    /** Deletes a tree build() wrote. */
    public static function remove(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Runs nfcapd on a free local port while $send writes to a socket connected to it.
     *
     * @param \Closure(resource): void $send
     */
    private static function collect(string $spool, string $bin, \Closure $send): void {
        $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($probe === false) {
            throw new \RuntimeException('No free UDP port: ' . $errstr);
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $log = $spool . '/nfcapd.log';
        $nfcapd = proc_open(
            [$bin . '/nfcapd', '-w', $spool, '-b', '127.0.0.1', '-p', (string) $port, '-z=lz4', '-B', '16777216'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']],
            $pipes,
        );
        if ($nfcapd === false) {
            throw new \RuntimeException('nfcapd did not start.');
        }

        try {
            self::waitFor(static fn (): bool => (glob($spool . '/nfcapd.current.*') ?: []) !== []);
            $socket = stream_socket_client("udp://127.0.0.1:{$port}", $errno, $errstr);
            if ($socket === false) {
                throw new \RuntimeException('Cannot reach nfcapd: ' . $errstr);
            }
            $send($socket);
            fclose($socket);
            // nfcapd has read everything once its log names the last exporter and a moment passed.
            self::waitFor(static fn (): bool => str_contains((string) file_get_contents($log), 'New exporter'));
            usleep(300_000);
        } finally {
            proc_terminate($nfcapd);
            self::waitFor(static fn (): bool => !proc_get_status($nfcapd)['running'], 500);
            if (proc_get_status($nfcapd)['running']) {
                proc_terminate($nfcapd, 9);
            }
            proc_close($nfcapd);
        }
        unlink($log);
    }

    /** @param resource $socket */
    private static function sendFile($socket, int $engineType, int $engineId, int $slot, int $flows, int $outPercent): void {
        $exportSecs = $slot + 400;
        $v5 = ['sequence' => 0, 'records' => '', 'count' => 0];
        $v9 = ['sequence' => 0, 'records' => '', 'count' => 0];
        $sent = 0;
        for ($i = 0; $i < $flows; ++$i) {
            $fields = self::record($slot, $exportSecs);
            if ($outPercent > 0 && random_int(0, 99) < $outPercent) {
                $outPackets = intdiv($fields[5] * random_int(0, 300), 100);
                $fields[] = $outPackets;
                $fields[] = min(0xFF_FF_FF_FF, $outPackets * random_int(40, 1500));
                $v9['records'] .= pack('NNNnnNNNNnnCCCnnCCNNCC', ...[...$fields, $engineType, $engineId]);
                ++$v9['count'];
            } else {
                $v5['records'] .= pack('NNNnnNNNNnnxCCCnnCCxx', ...$fields);
                ++$v5['count'];
            }

            $last = $i === $flows - 1;
            if ($v5['count'] === self::RECORDS_PER_PACKET || ($last && $v5['count'] > 0)) {
                fwrite($socket, pack('nnNNNNCCn', 5, $v5['count'], self::UPTIME_MS, $exportSecs, 0, $v5['sequence'], $engineType, $engineId, 0) . $v5['records']);
                $v5['sequence'] += $v5['count'];
                $sent += $v5['count'];
                $v5['records'] = '';
                $v5['count'] = 0;
            }
            if ($v9['count'] === self::RECORDS_PER_PACKET || ($last && $v9['count'] > 0)) {
                fwrite($socket, self::v9Packet($v9['records'], $v9['count'], $exportSecs, $v9['sequence']++, $engineType << 8 | $engineId));
                $sent += $v9['count'];
                $v9['records'] = '';
                $v9['count'] = 0;
            }
            // nfcapd drops what its socket buffer cannot hold.
            if ($sent >= self::RECORDS_PER_PACKET * 200) {
                usleep(2_000);
                $sent = 0;
            }
        }
    }

    /** A v9 packet of the template and $count data records, of one source id per source and file. */
    private static function v9Packet(string $records, int $count, int $exportSecs, int $sequence, int $sourceId): string {
        $template = pack('nn', 256, \count(self::V9_FIELDS));
        foreach (self::V9_FIELDS as [$type, $length]) {
            $template .= pack('nn', $type, $length);
        }
        $padding = str_repeat("\0", (4 - \strlen($records) % 4) % 4);

        return pack('nnNNNN', 9, 1 + $count, self::UPTIME_MS, $exportSecs, $sequence, $sourceId)
            . pack('nn', 0, 4 + \strlen($template)) . $template
            . pack('nn', 256, 4 + \strlen($records) + \strlen($padding)) . $records . $padding;
    }

    /**
     * One flow of a client and a server, or its reverse, in the order of a v5 record.
     *
     * @return list<int>
     */
    private static function record(int $slot, int $exportSecs): array {
        $client = self::zipf(4000, 1.1);
        $external = random_int(0, 99) < 75;
        $server = $external ? self::zipf(40000, 1.2) : self::zipf(250, 1.3);
        $icmp = random_int(0, 99) < 3;
        [$servicePort, $proto] = $icmp ? [0, 1] : self::SERVICES[self::zipf(\count(self::SERVICES), 1.4) - 1];

        $clientIp = 0x0A_00_00_00 | (($client >> 8) + 1) << 16 | ($client & 0xFF) << 8 | 1 + $client % 250;
        $serverIp = $external
            ? (((23 + $server * 7 % 200) << 24) | (($server * 2_654_435_761) & 0xFF_FF_FF)) & 0xFF_FF_FF_FF
            : 0x0A_32_00_00 | $server << 4 & 0xFF_00 | 1 + $server % 250;
        $serverAs = $external ? 64_512 + self::zipfOf($server, 400) : 0;
        $clientPort = $icmp ? 0 : 32_768 + random_int(0, 28_231);
        $servicePort = $icmp ? (random_int(0, 1) === 0 ? 8 << 8 : 0) : $servicePort;

        $packets = min(2_000_000, (int) floor(1 / (random_int(1, 1_000_000) / 1_000_000) ** (1 / 1.15)));
        $reverse = random_int(0, 99) < 45;
        $bpp = $reverse && !$icmp ? random_int(200, 1500) : random_int(40, 220);
        $bytes = min(0xFF_FF_FF_FF, $packets * $bpp);
        $firstMs = ($slot * 1000) + random_int(0, 299_999);
        $lastMs = $packets === 1 ? $firstMs : $firstMs + min(60_000, $packets * random_int(1, 40));
        $exportMs = $exportSecs * 1000;
        $inside = 1 + $client % 2;
        $outside = $external ? 3 + $server % 2 : 2 - $client % 2;
        $tos = [0, 0, 0, 0, 0, 0, 0, 0, 0x20, 0xB8][random_int(0, 9)];
        $flags = $proto === 6 ? self::TCP_FLAGS[random_int(0, \count(self::TCP_FLAGS) - 1)] : 0;
        $serverMask = $external ? 16 + $server % 9 : 24;

        [$src, $dst, $srcPort, $dstPort, $in, $out, $srcAs, $dstAs, $srcMask, $dstMask] = $reverse && !$icmp
            ? [$serverIp, $clientIp, $servicePort, $clientPort, $outside, $inside, $serverAs, 0, $serverMask, 24]
            : [$clientIp, $serverIp, $clientPort, $servicePort, $inside, $outside, 0, $serverAs, 24, $serverMask];

        return [
            $src, $dst, 0x0A_00_00_00 | $out, $in, $out, $packets, $bytes, self::UPTIME_MS - ($exportMs - $firstMs), self::UPTIME_MS - ($exportMs - $lastMs),
            $srcPort, $dstPort, $flags, $proto, $tos, $srcAs, $dstAs, $srcMask, $dstMask,
        ];
    }

    /** A rank from 1 to $n, rank r drawn with a weight of about r^-$s. */
    private static function zipf(int $n, float $s): int {
        $u = random_int(1, 1_000_000) / 1_000_000;
        $rank = (int) floor((($n ** (1 - $s) - 1) * $u + 1) ** (1 / (1 - $s)));

        return max(1, min($n, $rank));
    }

    /** A stable rank from 1 to $n for a key, so a host keeps its AS. */
    private static function zipfOf(int $key, int $n): int {
        return 1 + ($key * 7919) % $n;
    }

    /** @param list<string> $command */
    private static function run(array $command): string {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new \RuntimeException('Cannot start ' . $command[0]);
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException(basename($command[0]) . ' failed (' . $code . '): ' . trim($err . ' ' . $out));
        }

        return $out;
    }

    private static function waitFor(\Closure $ready, int $tries = 250): void {
        for ($i = 0; $i < $tries && !$ready(); ++$i) {
            usleep(20_000);
        }
    }
}
