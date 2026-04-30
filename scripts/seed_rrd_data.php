#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed RRD files with realistic synthetic NetFlow protocol data.
 *
 * Generates 90 days (default) of diurnal traffic with TCP/UDP/ICMP/Other
 * protocol breakdown, suitable for screenshot-quality graphs in nfsen-ng.
 *
 * Uses Config::initialize() and Rrd::write() directly — the same code path
 * as the live import daemon — so the resulting RRD structure is identical
 * to real imported data.
 *
 * Usage (run from the nfsen-ng repository root):
 *   php scripts/seed_rrd_data.php [--source=all] [--profile=live] [--days=90]
 *
 * Options map to Config / environment as follows:
 *   --source   must be a name listed in NFSEN_SOURCES (or general.sources)
 *   --profile  nfdump profile subdirectory (default: NFSEN_NFDUMP_PROFILE or 'live')
 *   --days     number of days of history to generate (default: 90)
 *
 * Port data is written automatically for every port in NFSEN_PORTS / general.ports.
 */

// ─── Bootstrap ───────────────────────────────────────────────────────────────

require_once \dirname(__DIR__) . '/vendor/autoload.php';

use mbolli\nfsen_ng\common\Config;

Config::initialize();

if (!Config::$db instanceof \mbolli\nfsen_ng\datasources\Rrd) {
    echo "Error: configured datasource is not RRD (got " . Config::$settings->datasourceName . ").\n";
    echo "This script only works with the RRD datasource.\n";
    exit(1);
}

// ─── Options ─────────────────────────────────────────────────────────────────

$opts = getopt('', ['source::', 'profile::', 'days::']);

$source  = (string) ($opts['source']  ?? Config::$settings->sources[0] ?? 'all');
$profile = (string) ($opts['profile'] ?? Config::$settings->nfdumpProfile);
$days    = max(1, (int) ($opts['days'] ?? 90));
$ports   = Config::$settings->ports;

const STEP = 300; // 5-minute resolution (must match Import pipeline)

$now   = (int) (floor(time() / STEP) * STEP);
$start = $now - ($days * 86400);

// Align start to an RRD step boundary
$start = $start - ($start % STEP);

printf(
    "Seeding %d days → %s to %s\nSource: %s  Profile: %s  Ports: %s\nRRD path: %s\n\n",
    $days,
    date('Y-m-d H:i', $start),
    date('Y-m-d H:i', $now),
    $source,
    $profile,
    $ports !== [] ? implode(', ', $ports) : '(none)',
    Config::$db->get_data_path($source, 0, $profile),
);

// ─── Traffic model ───────────────────────────────────────────────────────────

// Peak flows per 5-min interval at the busiest hour (Mon–Fri, ~13:00 UTC).
// Realistic enterprise uplink: ~25 k flows/5 min at peak.
const PEAK_FLOWS = 25_000;

// Protocol mix ratios (will be jittered ±8 % per interval).
$baseRatios = [
    'tcp'   => 0.63,
    'udp'   => 0.22,
    'icmp'  => 0.04,
    'other' => 0.11,
];

// Avg packets/flow and bytes/packet per protocol (used for Gaussian sampling).
$pktModel  = ['tcp' => [10.0, 3.0], 'udp' => [4.0, 1.5], 'icmp' => [2.0, 0.5], 'other' => [5.0, 2.0]];
$byteModel = ['tcp' => [800.0, 300.0], 'udp' => [400.0, 150.0], 'icmp' => [64.0, 10.0], 'other' => [500.0, 200.0]];

// ─── Main loop ───────────────────────────────────────────────────────────────

$totalIntervals = 0;
$totalErrors    = 0;
$dayStart       = $start;

while ($dayStart < $now) {
    $dayEnd    = min($dayStart + 86400, $now);
    $dayOk     = 0;
    $dayFailed = 0;

    for ($ts = $dayStart; $ts < $dayEnd; $ts += STEP) {
        // Diurnal curve: sin peak at ~13:00 UTC, trough at ~01:00 UTC.
        $hourOfDay = ($ts % 86400) / 3600.0;
        $sinVal    = sin(M_PI * ($hourOfDay - 7.0) / 12.0); // 0 at 07:00 & 19:00
        $diurnal   = max(0.04, ($sinVal + 1.0) / 2.0);      // [0.04, 1.0]

        // Weekends are ~30 % quieter.
        $dowFactor = ((int) date('N', $ts) >= 6) ? 0.70 : 1.0;

        // Total flows for this interval, with ±15 % random noise.
        $totalFlows = max(10, (int) round(PEAK_FLOWS * $diurnal * $dowFactor * gauss(1.0, 0.15)));

        // Protocol split: jitter each ratio independently, force sum = 1.
        $splits      = jitterRatios($baseRatios, 0.08);
        $protos      = array_keys($splits);
        $lastProto   = end($protos);
        $protoFlows   = [];
        $protoPackets = [];
        $protoBytes   = [];
        $assignedFlows = 0;

        foreach ($protos as $proto) {
            if ($proto === $lastProto) {
                $f = max(1, $totalFlows - $assignedFlows);
            } else {
                $f = max(1, (int) round($totalFlows * $splits[$proto]));
                $assignedFlows += $f;
            }

            [$pktMean, $pktStd]   = $pktModel[$proto];
            [$byteMean, $byteStd] = $byteModel[$proto];

            $pkts  = max(1, (int) round($f * max(1.0, gauss($pktMean, $pktStd))));
            $bytes = max(1, (int) round($pkts * max(1.0, gauss($byteMean, $byteStd))));

            $protoFlows[$proto]   = $f;
            $protoPackets[$proto] = $pkts;
            $protoBytes[$proto]   = $bytes;
        }

        $sumFlows   = array_sum($protoFlows);
        $sumPackets = array_sum($protoPackets);
        $sumBytes   = array_sum($protoBytes);

        // Build the fields array that Rrd::write() expects.
        $fields = [
            'flows'   => $sumFlows,
            'packets' => $sumPackets,
            'bytes'   => $sumBytes,
        ];
        foreach ($protoFlows as $proto => $f) {
            $fields['flows_' . $proto]   = $f;
            $fields['packets_' . $proto] = $protoPackets[$proto];
            $fields['bytes_' . $proto]   = $protoBytes[$proto];
        }

        $data = [
            'fields'         => $fields,
            'source'         => $source,
            'port'           => 0,
            'profile'        => $profile,
            'date_iso'       => date('Ymd\THis', $ts),
            'date_timestamp' => $ts,
        ];

        $ok = Config::$db->write($data);
        if ($ok) {
            ++$dayOk;
        } else {
            ++$dayFailed;
            ++$totalErrors;
        }

        // Port data — each configured port gets ~10 % of source traffic with a TCP-dominant mix.
        $portRatios = ['tcp' => 0.82, 'udp' => 0.10, 'icmp' => 0.02, 'other' => 0.06];
        foreach ($ports as $port) {
            $portShare   = max(0.01, gauss(0.10, 0.30));
            $portFlows   = max(1, (int) round($sumFlows   * $portShare));
            $portPackets = max(1, (int) round($sumPackets * $portShare));
            $portBytes   = max(1, (int) round($sumBytes   * $portShare));

            $portSplits       = jitterRatios($portRatios, 0.10);
            $portProtoFlows   = [];
            $portProtoPackets = [];
            $portProtoBytes   = [];
            $portAssigned     = 0;
            $portProtos       = array_keys($portSplits);
            $portLast         = end($portProtos);

            foreach ($portProtos as $proto) {
                if ($proto === $portLast) {
                    $pf = max(1, $portFlows - $portAssigned);
                } else {
                    $pf = max(1, (int) round($portFlows * $portSplits[$proto]));
                    $portAssigned += $pf;
                }
                [$pktMean, $pktStd]   = $pktModel[$proto];
                [$byteMean, $byteStd] = $byteModel[$proto];
                $pp = max(1, (int) round($pf * max(1.0, gauss($pktMean, $pktStd))));
                $pb = max(1, (int) round($pp * max(1.0, gauss($byteMean, $byteStd))));
                $portProtoFlows[$proto]   = $pf;
                $portProtoPackets[$proto] = $pp;
                $portProtoBytes[$proto]   = $pb;
            }

            $portFields = [
                'flows'   => $portFlows,
                'packets' => $portPackets,
                'bytes'   => $portBytes,
            ];
            foreach ($portProtoFlows as $proto => $pf) {
                $portFields['flows_' . $proto]   = $pf;
                $portFields['packets_' . $proto] = $portProtoPackets[$proto];
                $portFields['bytes_' . $proto]   = $portProtoBytes[$proto];
            }

            $ok = Config::$db->write([
                'fields'         => $portFields,
                'source'         => $source,
                'port'           => $port,
                'profile'        => $profile,
                'date_iso'       => date('Ymd\THis', $ts),
                'date_timestamp' => $ts,
            ]);
            if ($ok) {
                ++$dayOk;
            } else {
                ++$dayFailed;
                ++$totalErrors;
            }
        }

        ++$totalIntervals;
    }

    $status = $dayFailed === 0 ? 'OK' : "{$dayFailed} failed";
    printf("  %s  %4d intervals  %s\n", date('Y-m-d', $dayStart), $dayOk + $dayFailed, $status);

    $dayStart = $dayEnd;
}

printf(
    "\nDone. %d intervals, %d write errors (%d days).\n",
    $totalIntervals,
    $totalErrors,
    $days,
);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Box-Muller Gaussian sample around $mean with relative std-dev $stdFraction.
 * Returns a value in approximately [mean*(1-3σ), mean*(1+3σ)].
 */
function gauss(float $mean, float $stdFraction): float {
    static $spare = null;

    if ($spare !== null) {
        $z     = $spare;
        $spare = null;

        return $mean + $z * $stdFraction * $mean;
    }

    do {
        $u = (mt_rand() / getrandmax()) * 2.0 - 1.0;
        $v = (mt_rand() / getrandmax()) * 2.0 - 1.0;
        $s = $u * $u + $v * $v;
    } while ($s >= 1.0 || $s === 0.0);

    $mul   = sqrt(-2.0 * log($s) / $s);
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
