<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\CaptureInterval;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Rrd;

/**
 * A profiles tree with empty capture files for `gw` named after `$stamps` in the nfcapd timezone.
 *
 * @param list<int> $stamps
 */
function captureIntervalTree(array $stamps): string {
    $dir = sys_get_temp_dir() . '/capint_' . uniqid();
    foreach ($stamps as $ts) {
        $at = (new DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());
        $day = $dir . '/live/gw/' . $at->format('Y/m/d');
        is_dir($day) || mkdir($day, 0o755, true);
        touch($day . '/nfcapd.' . $at->format('YmdHi'));
    }
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw']],
        'nfdump' => ['profiles-data' => $dir, 'profile' => 'live'],
    ]);
    CaptureInterval::reset();

    return $dir;
}

function captureIntervalClean(string $dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
    CaptureInterval::reset();
}

/** Midnight of 2026-10-01 plus `$days` in the nfcapd timezone. */
function captureIntervalDay(int $days = 0): int {
    return (new DateTimeImmutable('2026-10-01', Config::nfcapdTimezone()))->modify("+{$days} day")->getTimestamp();
}

describe('CaptureInterval::fromStamps()', function (): void {
    test('the most common gap wins over a missing file', function (): void {
        expect(CaptureInterval::fromStamps([0, 60, 120, 300, 360, 420]))->toBe(60);
    });

    test('too few gaps tell nothing', function (): void {
        expect(CaptureInterval::fromStamps([0, 60, 120]))->toBeNull();
        expect(CaptureInterval::fromStamps([0, 300, 600, 900]))->toBe(300);
    });

    test('a tie goes to the shorter gap', function (): void {
        expect(CaptureInterval::fromStamps([0, 60, 120, 420, 720]))->toBe(60);
    });
});

describe('CaptureInterval::forFile()', function (): void {
    afterEach(function (): void {
        captureIntervalClean($this->dir);
    });

    test('reads each day on its own, so a switch of nfcapd -t keeps both', function (): void {
        $day1 = captureIntervalDay();
        $day2 = captureIntervalDay(1);
        $this->dir = captureIntervalTree([
            ...range($day1 + 3600, $day1 + 7200, 300),
            ...range($day2 + 3600, $day2 + 4200, 60),
        ]);

        expect(CaptureInterval::forFile('live', 'gw', $day1 + 3600))->toBe(300);
        expect(CaptureInterval::forFile('live', 'gw', $day2 + 3600))->toBe(60);
    });

    test('a day with too few files falls back to the day before', function (): void {
        $day1 = captureIntervalDay();
        $this->dir = captureIntervalTree([...range($day1 + 3600, $day1 + 4200, 60), captureIntervalDay(1)]);

        expect(CaptureInterval::forFile('live', 'gw', captureIntervalDay(1)))->toBe(60);
        expect(CaptureInterval::forFile('live', 'gw', captureIntervalDay(2)))->toBeNull();
    });
});

describe('Rrd::updateTime()', function (): void {
    test('files shorter than the step land inside the slot of the file starting it', function (): void {
        // Files 12:00 to 12:04 go to 11:56 to 12:00, all inside the row stamped 12:00.
        expect(array_map(static fn (int $k): int => Rrd::updateTime(43200 + 60 * $k, 60, 300), range(0, 4)))
            ->toBe([42960, 43020, 43080, 43140, 43200])
        ;
    });

    test('one file per step, or an unknown interval, keeps the file time', function (): void {
        expect(Rrd::updateTime(43200, 300, 300))->toBe(43200);
        expect(Rrd::updateTime(43200, null, 300))->toBe(43200);
        expect(Rrd::updateTime(43200, 600, 300))->toBe(43200);
    });
});

describe('AlertManager::completeSlot()', function (): void {
    test('a slot still filling is skipped for the one before', function (): void {
        expect(AlertManager::completeSlot(43200 + 180, 60))->toBe(42900);
        expect(AlertManager::completeSlot(43200 + 240, 60))->toBe(43200);
    });

    test('one file per slot is its own slot', function (): void {
        expect(AlertManager::completeSlot(43200, 300))->toBe(43200);
        expect(AlertManager::completeSlot(43200, null))->toBe(43200);
    });
});

describe('HealthChecker::intervalCheck()', function (): void {
    test('files that divide the step are fine', function (): void {
        expect(HealthChecker::intervalCheck(300, 300)['status'])->toBe('ok');
        $minute = HealthChecker::intervalCheck(60, 300);
        expect($minute['status'])->toBe('ok');
        expect($minute['hint'])->toContain('5 files');
    });

    test('files rarer than the step, or not dividing it, warn', function (): void {
        expect(HealthChecker::intervalCheck(600, 300)['status'])->toBe('warning');
        expect(HealthChecker::intervalCheck(120, 300)['status'])->toBe('warning');
    });

    test('too few files is no warning', function (): void {
        expect(HealthChecker::intervalCheck(null, 300)['status'])->toBe('ok');
    });
});
