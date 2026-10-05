<?php

declare(strict_types=1);

/*
 * Capture files for the book's screenshots: two gateways of a small office, written by a real nfcapd
 * (tests/Support/captures/FixtureCaptures.php). Seeded, so a rerun writes the same flows.
 *
 *   php book/_seed-captures.php [--from=<unix>] [--to=<unix>] [--seed=1] <profiles-data>
 *
 * Without --from and --to it writes the 48 hours up to the current 5-minute slot. Outside hosts use only
 * the documentation ranges (RFC 5737) and private-use AS numbers, so no image shows a real host.
 */

require __DIR__ . '/../vendor/autoload.php';

use Tests\Support\captures\FixtureCaptures;

const SOURCES = ['gw1', 'gw2'];

/** Peak flows per 5 minutes: gw1 is the main office, gw2 a branch. */
const PEAK = [6000, 1800];

$args = getopt('', ['from:', 'to:', 'seed:'], $rest);
$root = $argv[$rest] ?? null;
if ($root === null) {
    fwrite(STDERR, "usage: php book/_seed-captures.php [--from=<unix>] [--to=<unix>] [--seed=1] <profiles-data>\n");

    exit(2);
}
$to = isset($args['to']) ? (int) $args['to'] : intdiv(time(), 300) * 300;
$from = isset($args['from']) ? (int) $args['from'] : $to - 48 * 3600;
$seed = (int) ($args['seed'] ?? 1);
$from -= $from % 300;

/** A uniform number in [0, 1) from mt_rand, which FixtureCaptures seeds per file. */
function rnd(): float {
    return mt_rand() / (getrandmax() + 1);
}

function pick(array $weighted): mixed {
    $r = rnd() * array_sum(array_column($weighted, 1));
    foreach ($weighted as [$value, $weight]) {
        if (($r -= $weight) < 0) {
            return $value;
        }
    }

    return $weighted[array_key_last($weighted)][0];
}

/** Rank 1 to $n, rank 1 most often. */
function zipf(int $n, float $s = 1.1): int {
    static $cdf = [];
    $key = "{$n}:{$s}";
    if (!isset($cdf[$key])) {
        $sum = 0.0;
        $c = [];
        for ($k = 1; $k <= $n; ++$k) {
            $c[] = $sum += 1 / $k ** $s;
        }
        $cdf[$key] = array_map(static fn (float $v): float => $v / $sum, $c);
    }
    $u = rnd();
    foreach ($cdf[$key] as $i => $v) {
        if ($u < $v) {
            return $i + 1;
        }
    }

    return $n;
}

function ip(string $dotted): int {
    return (int) ip2long($dotted);
}

/** A heavy-tailed size between $min and $max bytes. */
function size(int $min, int $max, float $alpha = 1.2): int {
    return (int) min($max, $min / (1 - rnd()) ** (1 / $alpha));
}

// The office: servers, wired clients, Wi-Fi clients; the gateways' inside interfaces are 1 and 2.
const DNS = '192.168.1.53';
const FILES = '192.168.1.10';
const WEB = '192.168.1.20';
const MAIL = '192.168.1.25';
const VPN = '192.168.1.40';
const BACKUP_TARGET = '203.0.113.200';

/** An office client: gw1 has two VLANs and about 180 hosts, gw2 one VLAN of 40. */
function client(int $source): int {
    if ($source === 1) {
        return ip('192.168.30.0') + 10 + zipf(40, 0.9);
    }

    return (rnd() < 0.65 ? ip('192.168.10.0') : ip('192.168.20.0')) + 10 + zipf(120, 0.9);
}

/** Outside hosts per role, all in 192.0.2.0/24, 198.51.100.0/24 and 203.0.113.0/24. */
function outside(string $role): int {
    return match ($role) {
        'cdn' => ip('198.51.100.0') + zipf(60, 1.3),
        'web' => ip('192.0.2.0') + zipf(250, 1.15),
        'stream' => ip('198.51.100.200') + zipf(20, 1.0),
        'call' => ip('198.51.100.150') + zipf(16, 1.2),
        'dns' => ip('198.51.100.53') + (rnd() < 0.7 ? 0 : 1),
        'ntp' => ip('203.0.113.123'),
        'mail' => ip('203.0.113.10') + zipf(30, 1.1),
        'remote' => ip('203.0.113.50') + zipf(12, 1.0),
        'visitor' => ip('192.0.2.0') + zipf(250, 0.8),
        'scanner' => ip('203.0.113.66') + zipf(3, 1.0),
        default => ip('192.0.2.1'),
    };
}

/** Private-use AS numbers by /26, so a few networks lead the AS table. */
function asOf(int $addr): int {
    $net = $addr & 0xFF_FF_FF_00;
    if ($net !== ip('192.0.2.0') && $net !== ip('198.51.100.0') && $net !== ip('203.0.113.0')) {
        return 0;
    }
    $block = ($addr & 0xFF) >> 6;

    return match ($net) {
        ip('198.51.100.0') => [64_600, 64_601, 64_602, 64_603][$block],
        ip('192.0.2.0') => [64_610, 64_611, 64_612, 64_613][$block],
        default => [64_620, 64_621, 64_622, 64_623][$block],
    };
}

function inside(int $addr): bool {
    return ($addr & 0xFF_FF_00_00) === ip('192.168.0.0');
}

/** The share of the day's traffic at a time: office hours, streaming in the evening, little at night. Every day is a workday. */
function load(int $source, int $ts): float {
    $h = ($ts % 86400) / 3600;
    $bump = static fn (float $at, float $width): float => exp(-(($h - $at) ** 2) / (2 * $width ** 2));
    $office = 0.75 * $bump(10.5, 1.6) + 0.85 * $bump(14.5, 1.8);
    $evening = $source === 0 ? 0.45 * $bump(20.5, 1.4) : 0.0;
    $level = 0.1 + $office + $evening;
    $noise = crc32("load:{$source}:{$ts}") % 1000 / 1000;

    return min(1.0, $level) * (0.9 + 0.2 * $noise);
}

/** The nightly backup of gw1: rsync over SSH to an outside host from 02:00 to 02:40. */
function backup(int $source, int $ts): bool {
    $h = ($ts % 86400) / 3600;

    return $source === 0 && $h >= 2 && $h < 2.67;
}

/**
 * One flow in the order of a v5 record. $kind and its share depend on the time; about half the flows
 * of a conversation are its reverse.
 *
 * @return list<int>
 */
function flow(int $source, int $slot, int $exportSecs, int $i, int $count): array {
    $h = ($slot % 86400) / 3600;
    $officeHours = $h >= 8 && $h < 18;
    $evening = $h >= 18 && $h < 23.5;
    $backupFlows = backup($source, $slot) ? 36 : 0;

    $kind = $i < $backupFlows ? 'backup' : pick([
        ['web', 34], ['quic', 14], ['dns', 20], ['stream', $evening ? 9 : 1], ['call', $officeHours ? 6 : 0.5],
        ['files', $officeHours && $source === 0 ? 6 : 0.5], ['mail', 3], ['vpn', 3], ['inbound', $source === 0 ? 4 : 0],
        ['ssh', 1], ['ntp', 0.5], ['scan', 2.5], ['icmp', 1.5],
    ]);

    // [client, server, server port, proto, bytes from the client, bytes from the server, avg packet size, duration ms, flags]
    [$a, $b, $port, $proto, $up, $down, $pkt, $ms, $flags] = match ($kind) {
        'web' => [client($source), outside(rnd() < 0.5 ? 'cdn' : 'web'), rnd() < 0.85 ? 443 : 80, 6, size(900, 60_000), size(4_000, 25_000_000, 1.05), 900, 2_000 + (int) (rnd() * 40_000), 0x1B],
        'quic' => [client($source), outside('cdn'), 443, 17, size(1_500, 80_000), size(10_000, 40_000_000, 1.05), 1_200, 1_000 + (int) (rnd() * 60_000), 0],
        'dns' => rnd() < 0.8
            ? [client($source), ip(DNS), 53, 17, size(70, 400), size(120, 1_400), 110, 30, 0]
            : [ip(DNS), outside('dns'), 53, 17, size(70, 400), size(120, 1_400), 110, 40, 0],
        'stream' => [client($source), outside('stream'), 443, rnd() < 0.6 ? 17 : 6, size(20_000, 600_000), size(4_000_000, 250_000_000, 1.5), 1_350, 120_000 + (int) (rnd() * 180_000), 0x1B],
        'call' => [client($source), outside('call'), 3478 + (int) (rnd() * 4), 17, size(2_000_000, 40_000_000, 1.8), size(2_000_000, 60_000_000, 1.8), 1_000, 60_000 + (int) (rnd() * 240_000), 0],
        'files' => [client($source), ip(FILES), 445, 6, size(2_000, 40_000_000, 0.9), size(5_000, 200_000_000, 0.9), 1_300, 500 + (int) (rnd() * 120_000), 0x1B],
        'mail' => rnd() < 0.6
            ? [client($source), ip(MAIL), 993, 6, size(800, 30_000), size(2_000, 4_000_000), 800, 1_000 + (int) (rnd() * 30_000), 0x1B]
            : [outside('mail'), ip(MAIL), 25, 6, size(2_000, 9_000_000, 1.1), size(400, 3_000), 700, 500 + (int) (rnd() * 8_000), 0x1B],
        'vpn' => [outside('remote'), ip(VPN), 51820, 17, size(50_000, 80_000_000, 1.1), size(100_000, 300_000_000, 1.1), 1_100, 240_000, 0],
        'inbound' => [outside('visitor'), ip(WEB), 443, 6, size(600, 20_000), size(3_000, 3_000_000), 950, 300 + (int) (rnd() * 15_000), 0x1B],
        'ssh' => [ip('192.168.10.11'), ip('192.168.1.' . [10, 20, 25, 40, 53][(int) (rnd() * 5)]), 22, 6, size(5_000, 500_000), size(8_000, 2_000_000), 300, 10_000 + (int) (rnd() * 280_000), 0x1B],
        'ntp' => [ip(DNS), outside('ntp'), 123, 17, 76, 76, 76, 10, 0],
        'scan' => [outside('scanner'), rnd() < 0.5 ? ip(WEB) : ip('192.168.1.' . (int) (2 + rnd() * 250)), [22, 23, 3389, 445, 8080, 5900][(int) (rnd() * 6)], 6, 44, 0, 44, 0, 0x02],
        'icmp' => [client($source), outside('web'), 0, 1, 84 * (1 + (int) (rnd() * 4)), 84 * (1 + (int) (rnd() * 4)), 84, 3_000, 0],
        'backup' => [ip(FILES), ip(BACKUP_TARGET), 22, 6, size(50_000_000, 140_000_000, 2.5), size(200_000, 2_000_000), 1_400, 280_000, 0x1B],
    };

    // A scan is one-way; the rest is one direction of the conversation.
    $reverse = $kind !== 'scan' && $kind !== 'backup' && ($i % 2 === 1);
    $bytes = max(40, $reverse ? $down : $up);
    if ($bytes === 40 && $reverse) {
        $reverse = false;
        $bytes = max(40, $up);
    }
    $packets = max(1, (int) ceil($bytes / $pkt));
    $clientPort = $proto === 1 ? 0 : 49_152 + (int) (rnd() * 16_383);
    $serverPort = $proto === 1 ? 8 << 8 : $port;
    [$src, $dst, $srcPort, $dstPort] = $reverse ? [$b, $a, $serverPort, $clientPort] : [$a, $b, $clientPort, $serverPort];

    $firstMs = $slot * 1000 + (int) (rnd() * 299_000);
    $lastMs = min($slot * 1000 + 299_999, $firstMs + $ms);
    $exportMs = $exportSecs * 1000;
    $uptime = 1_000_000_000;
    $srcInside = inside($src);
    $dstInside = inside($dst);
    $in = $srcInside ? 1 + $source : 3 + $source;
    $out = $dstInside ? 1 + $source : 3 + $source;
    $tos = $kind === 'call' ? 0xB8 : ($kind === 'backup' ? 0x20 : 0);
    $tcpFlags = $proto === 6 ? ($kind === 'scan' ? 0x02 : [0x1B, 0x1B, 0x1B, 0x1A, 0x18, 0x14][(int) (rnd() * 6)]) : 0;

    return [
        $src, $dst, ip('192.168.0.1'), $in, $out, $packets, min(0xFF_FF_FF_FF, $bytes),
        $uptime - ($exportMs - $firstMs), $uptime - ($exportMs - $lastMs),
        $srcPort, $dstPort, $tcpFlags, $proto, $tos, asOf($src), asOf($dst), $srcInside ? 24 : 26, $dstInside ? 24 : 26,
    ];
}

$flows = static fn (int $source, int $slot): int => max(50, (int) round(PEAK[$source] * load($source, $slot))) + (backup($source, $slot) ? 36 : 0);
$record = static fn (int $source, int $slot, int $exportSecs, int $i): array => flow($source, $slot, $exportSecs, $i, 0);

// FixtureCaptures tags a file by engine id, so one build holds at most 255 files.
$total = 0;
for ($start = $from; $start < $to; $start += 48 * 300) {
    $files = min(48, intdiv($to - $start, 300));
    $total += FixtureCaptures::build($root, 'live', SOURCES, $start, $files, $flows, $seed, record: $record);
    fprintf(STDERR, "%s: %d flows so far\n", gmdate('Y-m-d H:i', $start + $files * 300), $total);
}
echo "{$total} flows in " . intdiv($to - $from, 300) . ' files per source, ' . gmdate('Y-m-d H:i', $from) . ' to ' . gmdate('Y-m-d H:i', $to) . " UTC\n";
