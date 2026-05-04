#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed VictoriaMetrics with realistic synthetic NetFlow protocol data.
 *
 * Generates 90 days (default) of diurnal traffic with TCP/UDP/ICMP/Other
 * protocol breakdown, suitable for screenshot-quality graphs in nfsen-ng.
 *
 * Metric format matches VictoriaMetrics::write() exactly:
 *   nfsen_flows{source="all"}                                    — aggregate total
 *   nfsen_flows_tcp{source="all",protocol="tcp"}                 — per-protocol
 *   nfsen_flows_tcp{source="all",port="80",protocol="tcp"}       — per-port per-protocol
 *   … (15 metrics per source + 15 per port per 5-min interval)
 *
 * Usage:
 *   php scripts/seed_vm_data.php [--host=localhost] [--port=8428] \
 *                                [--source=all] [--days=90] [--ports=80,443,22]
 *
 * Environment variables VM_HOST and VM_PORT are also accepted as defaults.
 */
$opts = getopt('', ['host::', 'port::', 'source::', 'days::', 'ports::']);

$vmHost = (string) ($opts['host'] ?? getenv('VM_HOST') ?: 'localhost');
$vmPort = (int) ($opts['port'] ?? getenv('VM_PORT') ?: 8428);
$source = (string) ($opts['source'] ?? 'all');
$days = max(1, (int) ($opts['days'] ?? 90));

$portsOpt = (string) ($opts['ports'] ?? '');
$ports = $portsOpt !== ''
    ? array_values(array_filter(array_map('intval', explode(',', $portsOpt)), fn ($p) => $p > 0))
    : [];

$writeUrl = "http://{$vmHost}:{$vmPort}/api/v1/import/prometheus";

const STEP = 300; // 5-minute resolution (must match Import pipeline)

$now = (int) (floor(time() / STEP) * STEP);
$start = $now - ($days * 86400);

printf(
    "Seeding %d days → %s to %s\nTarget: %s  source=%s%s\n\n",
    $days,
    date('Y-m-d H:i', $start),
    date('Y-m-d H:i', $now),
    $writeUrl,
    $source,
    $ports !== [] ? '  ports=' . implode(',', $ports) : '',
);

// ─── Traffic model ───────────────────────────────────────────────────────────

// Peak flows per 5-min interval at the busiest hour (Mon–Fri, ~13:00 UTC).
// Realistic enterprise uplink: ~25 k flows/5 min at peak.
const PEAK_FLOWS = 25_000;

// Protocol mix ratios (will be jittered ±8 % per interval).
$baseRatios = [
    'tcp' => 0.63,
    'udp' => 0.22,
    'icmp' => 0.04,
    'other' => 0.11,
];

// Avg packets/flow and bytes/packet per protocol (used for Gaussian sampling).
$pktModel = ['tcp' => [10.0, 3.0], 'udp' => [4.0, 1.5], 'icmp' => [2.0, 0.5], 'other' => [5.0, 2.0]];
$byteModel = ['tcp' => [800.0, 300.0], 'udp' => [400.0, 150.0], 'icmp' => [64.0, 10.0], 'other' => [500.0, 200.0]];

// ─── Main loop (one batch per day) ───────────────────────────────────────────

$totalIntervals = 0;
$dayStart = $start;

while ($dayStart < $now) {
    $dayEnd = min($dayStart + 86400, $now);
    $lines = [];

    for ($ts = $dayStart; $ts < $dayEnd; $ts += STEP) {
        // Diurnal curve: sin peak at ~13:00 UTC, trough at ~01:00 UTC.
        $hourOfDay = ($ts % 86400) / 3600.0;
        $sinVal = sin(M_PI * ($hourOfDay - 7.0) / 12.0); // 0 at 07:00 & 19:00
        $diurnal = max(0.04, ($sinVal + 1.0) / 2.0);      // [0.04, 1.0]

        // Weekends are ~30 % quieter.
        $dowFactor = ((int) date('N', $ts) >= 6) ? 0.70 : 1.0;

        // Total flows for this interval, with ±15 % random noise.
        $totalFlows = max(10, (int) round(PEAK_FLOWS * $diurnal * $dowFactor * gauss(1.0, 0.15)));

        // Protocol split: jitter each ratio independently, force sum = 1.
        $splits = jitterRatios($baseRatios, 0.08);
        $protos = array_keys($splits);
        $lastProto = end($protos);

        $protoFlows = [];
        $protoPackets = [];
        $protoBytes = [];
        $assignedFlows = 0;

        foreach ($protos as $proto) {
            if ($proto === $lastProto) {
                // Remainder to guarantee exact sum.
                $f = max(1, $totalFlows - $assignedFlows);
            } else {
                $f = max(1, (int) round($totalFlows * $splits[$proto]));
                $assignedFlows += $f;
            }

            [$pktMean, $pktStd] = $pktModel[$proto];
            [$byteMean, $byteStd] = $byteModel[$proto];

            $pkts = max(1, (int) round($f * max(1.0, gauss($pktMean, $pktStd))));
            $bytes = max(1, (int) round($pkts * max(1.0, gauss($byteMean, $byteStd))));

            $protoFlows[$proto] = $f;
            $protoPackets[$proto] = $pkts;
            $protoBytes[$proto] = $bytes;
        }

        $sumFlows = array_sum($protoFlows);
        $sumPackets = array_sum($protoPackets);
        $sumBytes = array_sum($protoBytes);
        $tsMs = $ts * 1000;

        // Aggregate totals — no port label (matches buildLabels($src, 0, null))
        $lines[] = "nfsen_flows{source=\"{$source}\"} {$sumFlows} {$tsMs}";
        $lines[] = "nfsen_packets{source=\"{$source}\"} {$sumPackets} {$tsMs}";
        $lines[] = "nfsen_bytes{source=\"{$source}\"} {$sumBytes} {$tsMs}";

        // Per-protocol series — matches buildLabels($src, 0, $proto) and buildMetricName($type, $proto)
        foreach ($protoFlows as $proto => $flows) {
            $pkts = $protoPackets[$proto];
            $bytes = $protoBytes[$proto];
            $lines[] = "nfsen_flows_{$proto}{source=\"{$source}\",protocol=\"{$proto}\"} {$flows} {$tsMs}";
            $lines[] = "nfsen_packets_{$proto}{source=\"{$source}\",protocol=\"{$proto}\"} {$pkts} {$tsMs}";
            $lines[] = "nfsen_bytes_{$proto}{source=\"{$source}\",protocol=\"{$proto}\"} {$bytes} {$tsMs}";
        }

        // Port data — each configured port gets ~10 % of source traffic with a TCP-dominant mix.
        $portRatios = ['tcp' => 0.82, 'udp' => 0.10, 'icmp' => 0.02, 'other' => 0.06];
        foreach ($ports as $portNum) {
            $portShare = max(0.01, gauss(0.10, 0.30));
            $portFlows = max(1, (int) round($sumFlows * $portShare));
            $portPackets = max(1, (int) round($sumPackets * $portShare));
            $portBytes = max(1, (int) round($sumBytes * $portShare));

            $portSplits = jitterRatios($portRatios, 0.10);
            $portProtoFlows = [];
            $portProtoPackets = [];
            $portProtoBytes = [];
            $portAssigned = 0;
            $portProtos = array_keys($portSplits);
            $portLast = end($portProtos);

            foreach ($portProtos as $proto) {
                if ($proto === $portLast) {
                    $pf = max(1, $portFlows - $portAssigned);
                } else {
                    $pf = max(1, (int) round($portFlows * $portSplits[$proto]));
                    $portAssigned += $pf;
                }
                [$pktMean, $pktStd] = $pktModel[$proto];
                [$byteMean, $byteStd] = $byteModel[$proto];
                $pp = max(1, (int) round($pf * max(1.0, gauss($pktMean, $pktStd))));
                $pb = max(1, (int) round($pp * max(1.0, gauss($byteMean, $byteStd))));
                $portProtoFlows[$proto] = $pf;
                $portProtoPackets[$proto] = $pp;
                $portProtoBytes[$proto] = $pb;
            }

            $lines[] = "nfsen_flows{source=\"{$source}\",port=\"{$portNum}\"} {$portFlows} {$tsMs}";
            $lines[] = "nfsen_packets{source=\"{$source}\",port=\"{$portNum}\"} {$portPackets} {$tsMs}";
            $lines[] = "nfsen_bytes{source=\"{$source}\",port=\"{$portNum}\"} {$portBytes} {$tsMs}";
            foreach ($portProtoFlows as $proto => $pf) {
                $pp = $portProtoPackets[$proto];
                $pb = $portProtoBytes[$proto];
                $lines[] = "nfsen_flows_{$proto}{source=\"{$source}\",port=\"{$portNum}\",protocol=\"{$proto}\"} {$pf} {$tsMs}";
                $lines[] = "nfsen_packets_{$proto}{source=\"{$source}\",port=\"{$portNum}\",protocol=\"{$proto}\"} {$pp} {$tsMs}";
                $lines[] = "nfsen_bytes_{$proto}{source=\"{$source}\",port=\"{$portNum}\",protocol=\"{$proto}\"} {$pb} {$tsMs}";
            }
        }

        ++$totalIntervals;
    }

    // POST day batch to VictoriaMetrics.
    $body = implode("\n", $lines);
    $code = vmPost($writeUrl, $body);

    $status = ($code === 204) ? 'OK' : "FAILED (HTTP {$code})";
    $dayIntervals = (int) (($dayEnd - $dayStart) / STEP);
    printf("  %s  %4d intervals  %6d lines  %s\n", date('Y-m-d', $dayStart), $dayIntervals, count($lines), $status);

    if ($code !== 204) {
        echo "  Aborting — check that VictoriaMetrics is reachable at {$writeUrl}\n";

        exit(1);
    }

    $dayStart = $dayEnd;
}

printf("\nDone. %d intervals written (%d days).\n", $totalIntervals, $days);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Box-Muller Gaussian sample around $mean with relative std-dev $stdFraction.
 * Returns a value in approximately [mean*(1-3σ), mean*(1+3σ)].
 */
function gauss(float $mean, float $stdFraction): float {
    static $spare = null;

    if ($spare !== null) {
        $z = $spare;
        $spare = null;

        return $mean + $z * $stdFraction * $mean;
    }

    do {
        $u = (mt_rand() / getrandmax()) * 2.0 - 1.0;
        $v = (mt_rand() / getrandmax()) * 2.0 - 1.0;
        $s = $u * $u + $v * $v;
    } while ($s >= 1.0 || $s === 0.0);

    $mul = sqrt(-2.0 * log($s) / $s);
    $spare = $v * $mul;

    return $mean + $u * $mul * $stdFraction * $mean;
}

/**
 * Jitter protocol ratios by ±$fraction and re-normalise so they sum to 1.
 *
 * @param array<string,float> $ratios
 *
 * @return array<string,float>
 */
function jitterRatios(array $ratios, float $fraction): array {
    $jittered = [];
    foreach ($ratios as $proto => $r) {
        $jittered[$proto] = max(0.005, $r * gauss(1.0, $fraction));
    }

    $total = array_sum($jittered);

    return array_map(static fn ($v) => $v / $total, $jittered);
}

/**
 * POST Prometheus-format lines to the VictoriaMetrics import endpoint.
 * Returns the HTTP status code, or 0 on connection failure.
 */
function vmPost(string $url, string $body): int {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: text/plain\r\nContent-Length: " . strlen($body),
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);

    if ($response === false || empty($http_response_header)) {
        return 0;
    }

    preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m);

    return isset($m[1]) ? (int) $m[1] : 0;
}
