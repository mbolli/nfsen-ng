<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\RangeActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\OverviewPage;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Tests\Support\FakeProcessor;

const RANGE_NOW = 1_790_000_000;
const RANGE_MIN = RANGE_NOW - 365 * 86400;

/**
 * @param array<string, mixed> $input
 *
 * @return array{datestart: int, dateend: int, range_live: bool, range_preset: string}
 */
function rangeOp(string $op, array $input = [], int $start = RANGE_NOW - 86400, int $end = RANGE_NOW, bool $live = true, string $preset = '24h'): array {
    return RangeActions::compute($op, $input, ['datestart' => $start, 'dateend' => $end, 'range_live' => $live, 'range_preset' => $preset], RANGE_NOW, RANGE_MIN);
}

describe('set-range ops', function (): void {
    test('a preset is a live window of its width ending now', function (): void {
        expect(rangeOp('preset', ['v' => '7d'], live: false, preset: 'custom'))->toBe([
            'datestart' => RANGE_NOW - 604800, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => '7d',
        ]);

        foreach (RangeControls::PRESETS as $id => $seconds) {
            $next = rangeOp('preset', ['v' => $id]);
            expect($next['dateend'] - $next['datestart'])->toBe($seconds);
        }
    });

    test('a custom duration is live, ends now and is marked custom', function (): void {
        expect(rangeOp('duration', ['n' => '6', 'u' => 'h']))->toBe([
            'datestart' => RANGE_NOW - 6 * 3600, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => 'custom',
        ])
            ->and(rangeOp('duration', ['n' => '3', 'u' => 'd'])['datestart'])->toBe(RANGE_NOW - 3 * 86400)
            ->and(rangeOp('duration', ['n' => '2', 'u' => 'w'])['datestart'])->toBe(RANGE_NOW - 2 * 604800)
            // 9999 weeks reach past 1970; the start stops there.
            ->and(rangeOp('duration', ['n' => '9999', 'u' => 'w'])['datestart'])->toBe(0)
        ;
    });

    test('an absolute range snaps to 5 minutes and is fixed when it ends in the past', function (): void {
        $from = RANGE_NOW - 7200 + 17;
        $to = RANGE_NOW - 3600 - 17;

        expect(rangeOp('abs', ['from' => (string) $from, 'to' => (string) $to]))->toBe([
            'datestart' => intdiv($from, 300) * 300,
            'dateend' => (int) ceil($to / 300) * 300,
            'range_live' => false,
            'range_preset' => 'custom',
        ]);
    });

    test('an absolute range is clamped to the data and to now, and is live when it reaches now', function (): void {
        $next = rangeOp('abs', ['from' => (string) (RANGE_MIN - 86400), 'to' => (string) (RANGE_NOW + 3600)]);

        expect($next['datestart'])->toBe(intdiv(RANGE_MIN, 300) * 300)
            ->and($next['dateend'])->toBe(RANGE_NOW)
            ->and($next['range_live'])->toBeTrue()
            // Ending within 5 minutes of now is live too.
            ->and(rangeOp('abs', ['from' => (string) (RANGE_NOW - 3600), 'to' => (string) (RANGE_NOW - 250)])['range_live'])->toBeTrue()
            ->and(rangeOp('abs', ['from' => (string) (RANGE_NOW - 3600), 'to' => (string) (RANGE_NOW - 600)])['range_live'])->toBeFalse()
        ;
    });

    test('an absolute range is at least 5 minutes wide', function (): void {
        $at = RANGE_NOW - 86400 + 60;
        $next = rangeOp('abs', ['from' => (string) $at, 'to' => (string) ($at + 1)]);
        $live = rangeOp('abs', ['from' => (string) (RANGE_NOW - 30), 'to' => (string) (RANGE_NOW - 10)]);

        expect($next['dateend'] - $next['datestart'])->toBe(300)
            ->and($live['dateend'])->toBe(RANGE_NOW)
            ->and($live['dateend'] - $live['datestart'])->toBeGreaterThanOrEqual(300)
        ;
    });

    test('step back moves one width into the past and stops at the start of the data', function (): void {
        expect(rangeOp('back'))->toBe([
            'datestart' => RANGE_NOW - 2 * 86400, 'dateend' => RANGE_NOW - 86400, 'range_live' => false, 'range_preset' => '24h',
        ]);

        $atStart = rangeOp('back', start: RANGE_MIN + 3600, end: RANGE_MIN + 3600 + 86400, live: false);
        expect($atStart['datestart'])->toBe(RANGE_MIN)
            ->and($atStart['dateend'] - $atStart['datestart'])->toBe(86400)
            // A window that already starts at the data's start has nothing before it.
            ->and(static fn () => rangeOp('back', start: RANGE_MIN, end: RANGE_NOW, preset: '1y'))->toThrow(InvalidArgumentException::class, 'Already at the start')
        ;
    });

    test('step forward moves one width ahead and turns live when it reaches now', function (): void {
        expect(rangeOp('forward', start: RANGE_NOW - 3 * 86400, end: RANGE_NOW - 2 * 86400, live: false))->toBe([
            'datestart' => RANGE_NOW - 2 * 86400, 'dateend' => RANGE_NOW - 86400, 'range_live' => false, 'range_preset' => '24h',
        ]);

        // Less than a width from now: it ends now, and keeps its width as the old slider did.
        expect(rangeOp('forward', start: RANGE_NOW - 30 * 3600, end: RANGE_NOW - 6 * 3600, live: false))->toBe([
            'datestart' => RANGE_NOW - 86400, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => '24h',
        ]);

        // Back and forward again a few seconds later is live again.
        expect(rangeOp('forward', start: RANGE_NOW - 2 * 86400 - 20, end: RANGE_NOW - 86400 - 20, live: false))->toBe([
            'datestart' => RANGE_NOW - 86400, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => '24h',
        ]);
    });

    test('now keeps the width, ends now and is live', function (): void {
        expect(rangeOp('now', start: RANGE_NOW - 10 * 86400, end: RANGE_NOW - 9 * 86400, live: false))->toBe([
            'datestart' => RANGE_NOW - 86400, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => '24h',
        ])
            ->and(rangeOp('now', start: RANGE_NOW - 7200, end: RANGE_NOW - 3600, live: false, preset: '24h')['range_preset'])->toBe('custom')
        ;
    });

    test('zoom out doubles the width around the centre, inside the data and now', function (): void {
        $centre = RANGE_NOW - 10 * 86400;
        expect(rangeOp('zoomout', start: $centre - 43200, end: $centre + 43200, live: false))->toBe([
            'datestart' => $centre - 86400, 'dateend' => $centre + 86400, 'range_live' => false, 'range_preset' => 'custom',
        ]);

        // A live window stays live and grows into the past, also a few seconds after it was set.
        expect(rangeOp('zoomout'))->toBe([
            'datestart' => RANGE_NOW - 2 * 86400, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => 'custom',
        ])
            ->and(rangeOp('zoomout', start: RANGE_NOW - 86400 - 30, end: RANGE_NOW - 30))->toBe([
                'datestart' => RANGE_NOW - 2 * 86400, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => 'custom',
            ])
        ;

        $early = rangeOp('zoomout', start: RANGE_MIN + 600, end: RANGE_MIN + 4200, live: false);
        expect($early['datestart'])->toBe(RANGE_MIN)
            ->and($early['dateend'])->toBe(RANGE_MIN + 7200)
        ;

        // Never wider than the data.
        $all = rangeOp('zoomout', start: RANGE_NOW - 300 * 86400, end: RANGE_NOW);
        expect($all['datestart'])->toBe(RANGE_MIN)
            ->and($all['dateend'])->toBe(RANGE_NOW)
            ->and($all['range_live'])->toBeTrue()
        ;
    });

    test('pin keeps the window and stops following the clock', function (): void {
        expect(rangeOp('pin'))->toBe([
            'datestart' => RANGE_NOW - 86400, 'dateend' => RANGE_NOW, 'range_live' => false, 'range_preset' => '24h',
        ]);
    });

    test('a live window counts from now, however long ago a render last moved it', function (): void {
        // Half an hour on a page that does not advance the window.
        $stale = ['start' => RANGE_NOW - 5400, 'end' => RANGE_NOW - 1800, 'preset' => '1h'];

        expect(rangeOp('back', ...$stale))->toBe([
            'datestart' => RANGE_NOW - 7200, 'dateend' => RANGE_NOW - 3600, 'range_live' => false, 'range_preset' => '1h',
        ])
            ->and(rangeOp('zoomout', ...$stale))->toBe([
                'datestart' => RANGE_NOW - 7200, 'dateend' => RANGE_NOW, 'range_live' => true, 'range_preset' => 'custom',
            ])
            ->and(rangeOp('pin', ...$stale))->toBe([
                'datestart' => RANGE_NOW - 3600, 'dateend' => RANGE_NOW, 'range_live' => false, 'range_preset' => '1h',
            ])
            // A fixed window is where it says.
            ->and(rangeOp('back', ...$stale, live: false))->toMatchArray(['datestart' => RANGE_NOW - 9000, 'dateend' => RANGE_NOW - 5400])
            ->and(rangeOp('pin', ...$stale, live: false))->toMatchArray(['datestart' => RANGE_NOW - 5400, 'dateend' => RANGE_NOW - 1800])
        ;
    });

    test('a window of no width counts as 5 minutes', function (): void {
        $next = rangeOp('back', start: RANGE_NOW - 3600, end: RANGE_NOW - 3600, live: false);

        expect($next['dateend'] - $next['datestart'])->toBe(300);
    });

    test('invalid input is refused with a message', function (array $call): void {
        [$op, $input] = $call;
        expect(static fn () => rangeOp($op, $input))->toThrow(InvalidArgumentException::class);
    })->with([
        'unknown op' => [['sideways', []]],
        'unknown preset' => [['preset', ['v' => '2h']]],
        'no preset' => [['preset', []]],
        'zero duration' => [['duration', ['n' => '0', 'u' => 'h']]],
        'duration too long' => [['duration', ['n' => '10000', 'u' => 'h']]],
        'fractional duration' => [['duration', ['n' => '1.5', 'u' => 'h']]],
        'unknown unit' => [['duration', ['n' => '3', 'u' => 'm']]],
        'no end' => [['abs', ['from' => '1000']]],
        'not a number' => [['abs', ['from' => 'yesterday', 'to' => '1000']]],
        'end before start' => [['abs', ['from' => (string) (RANGE_NOW - 3600), 'to' => (string) (RANGE_NOW - 7200)]]],
        'before the data' => [['abs', ['from' => (string) (RANGE_MIN - 7200), 'to' => (string) (RANGE_MIN - 3600)]]],
        'in the future' => [['abs', ['from' => (string) (RANGE_NOW + 60), 'to' => (string) (RANGE_NOW + 3600)]]],
    ]);
});

describe('apply-globals normalisation', function (): void {
    test('sources are cut to configured ones in their order; none or any means all', function (): void {
        $configured = ['gw1', 'gw2', 'gw3'];

        expect(RangeActions::normalizeSources(['gw3', 'gw1'], $configured))->toBe(['gw1', 'gw3'])
            ->and(RangeActions::normalizeSources(['gw2', 'bogus', ''], $configured))->toBe(['gw2'])
            ->and(RangeActions::normalizeSources([], $configured))->toBe($configured)
            ->and(RangeActions::normalizeSources(['any'], $configured))->toBe($configured)
            ->and(RangeActions::normalizeSources(['bogus'], $configured))->toBe($configured)
            ->and(RangeActions::normalizeSources('gw1', $configured))->toBe($configured)
            ->and(RangeActions::normalizeSources([['gw1']], $configured))->toBe($configured)
        ;
    });
});

describe('import chip', function (): void {
    test('hidden while no import runs and none failed', function (): void {
        expect(RangeControls::importChip(false, 'Import complete.', 'complete', 100, '')['visible'])->toBeFalse()
            ->and(RangeControls::importChip(false, '', '', 0, '')['visible'])->toBeFalse()
            // Neither the daemon's catch-up, which records no outcome, nor a profile's name is a failed pass.
            ->and(RangeControls::importChip(false, '[live] Catch-up failed: x', '', 0, '')['visible'])->toBeFalse()
            ->and(RangeControls::importChip(false, '[failed-sensors] Up to date', '', 0, '')['visible'])->toBeFalse()
        ;
    });

    test('neutral while an import runs, with its progress and ETA', function (): void {
        expect(RangeControls::importChip(true, 'Scanning 30 / 40 files', '', 75, '12s'))->toBe([
            'visible' => true, 'level' => '', 'label' => 'Import running', 'running' => true, 'progress' => 75, 'eta' => '12s',
        ])
            ->and(RangeControls::importChip(true, 'Counting files…', '', 0, '')['level'])->toBe('')
        ;
    });

    test('a warning while more than 12 files are pending', function (): void {
        expect(RangeControls::importChip(true, 'Scanning 1,200 / 1,213 files', '', 98, '1m'))->toMatchArray(['level' => 'warning', 'label' => 'Import behind'])
            ->and(RangeControls::importChip(true, '[live] Catching up: 2 / 14 files', '', 14, '')['level'])->toBe('')
            ->and(RangeControls::importChip(true, '[live] Catching up: 2 / 15 files', '', 13, '')['level'])->toBe('warning')
        ;
    });

    test('an error after an import pass failed, until the next one starts', function (): void {
        expect(RangeControls::importChip(false, 'Import failed: disk full', 'failed', 100, ''))->toMatchArray([
            'visible' => true, 'level' => 'error', 'label' => 'Import failed', 'running' => false,
        ])
            ->and(RangeControls::importChip(true, 'Counting files…', 'failed', 0, '')['level'])->toBe('')
        ;
    });
});

describe('the range actions never read capture files', function (): void {
    beforeEach(function (): void {
        $settings = new ReflectionProperty(Config::class, 'settings');
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $processor = new ReflectionProperty(Config::class, 'processorClass');
        $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
        $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;
        $this->processorBefore = $processor->isInitialized() ? Config::$processorClass : null;

        // No sources: the data range read after a profile change returns before any datasource.
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => [], 'ports' => [80]],
            'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-range-actions-test-missing', 'profile' => 'live'],
        ]);
        $this->prefsFile = tempnam(sys_get_temp_dir(), 'nfsen-range-prefs-');
        Config::$prefsFile = $this->prefsFile;
        Config::$processorClass = new FakeProcessor();
        FakeProcessor::reset();

        putenv('VIA_TEST_MODE=1');
        $this->c = new Context('ctx-range', '/', new Via(new ViaConfig()));
        $this->states = new PageStates();
        Shell::signals($this->c);
        RangeControls::signals($this->c);
        RangeActions::register($this->c, $this->states);
    });

    afterEach(function (): void {
        putenv('VIA_TEST_MODE');
        @unlink($this->prefsFile);
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
        if ($this->processorBefore !== null) {
            Config::$processorClass = $this->processorBefore;
        }
    });

    test('set-range, apply-globals and change-profile run without a single nfdump call', function (): void {
        $c = $this->c;
        $run = static function (string $action, array $query = []) use ($c): void {
            $c->setRequestInput($query, []);
            $c->executeAction((string) $c->getAction($action)?->id());
        };

        $this->states->shell->graphFetchedAt = 123;
        $hourAgo = (string) (time() - 3600);
        foreach ([['op' => 'preset', 'v' => '1h'], ['op' => 'back'], ['op' => 'forward'], ['op' => 'now'], ['op' => 'zoomout'],
            ['op' => 'pin'], ['op' => 'duration', 'n' => '3', 'u' => 'd'], ['op' => 'abs', 'from' => (string) (time() - 7200), 'to' => $hourAgo]] as $query) {
            $run('set-range', $query);
        }
        expect($c->getSignal('_error')?->string())->toBe('')
            ->and($this->states->shell->graphFetchedAt)->toBe(0)
        ;

        $c->getSignal('protocol')?->setValue('TCP ', broadcast: false);
        $c->getSignal('graph_trafficUnit')?->setValue('nibbles', broadcast: false);
        $run('apply-globals');
        expect($c->getSignal('protocol')?->string())->toBe('tcp')
            ->and($c->getSignal('graph_trafficUnit')?->string())->toBe('bits')
        ;

        $c->getSignal('selected_profile')?->setValue('live', broadcast: false);
        $run('change-profile');

        expect(FakeProcessor::$calls)->toBe([]);
    });

    test('set-range with invalid input keeps the window and says why, until an op works', function (): void {
        $c = $this->c;
        $before = [$c->getSignal('datestart')?->int(), $c->getSignal('dateend')?->int()];
        $c->setRequestInput(['op' => 'duration', 'n' => '0', 'u' => 'h'], []);
        $c->executeAction((string) $c->getAction('set-range')?->id());

        expect([$c->getSignal('datestart')?->int(), $c->getSignal('dateend')?->int()])->toBe($before)
            ->and($c->getSignal('_error')?->string())->toBe('Range: Enter a duration between 1 and 9999.')
        ;

        // The next op that works takes its own banner away, and leaves anyone else's.
        $c->setRequestInput(['op' => 'preset', 'v' => '1h'], []);
        $c->executeAction((string) $c->getAction('set-range')?->id());
        expect($c->getSignal('_error')?->string())->toBe('');

        $c->getSignal('_error')?->setValue('Graph error: no RRD', broadcast: false);
        $c->executeAction((string) $c->getAction('set-range')?->id());
        expect($c->getSignal('_error')?->string())->toBe('Graph error: no RRD');
    });

    test('change-profile keeps the width, follows the new profile to its newest data and is live while that is current', function (): void {
        $c = $this->c;
        $now = time();
        $switch = static function (int $last, bool $wasLive) use ($c, $now): bool {
            $c->getSignal('datestart')?->setValue(1_000_000, broadcast: false);
            $c->getSignal('dateend')?->setValue(1_007_200, broadcast: false);
            $c->getSignal('range_live')?->setValue($wasLive, broadcast: false);
            // Without sources the data range read keeps this as the newest sample.
            $c->getSignal('data_range_max')?->setValue($last, broadcast: false);
            $c->getSignal('selected_profile')?->setValue('live', broadcast: false);

            return RangeActions::changeProfile($c, $now);
        };
        $window = static fn (): array => [
            $c->getSignal('datestart')?->int(), $c->getSignal('dateend')?->int(), $c->getSignal('range_live')?->bool(),
        ];

        // A current profile's newest sample starts an interval that closed minutes ago.
        expect($switch($now - 420, false))->toBeTrue()
            ->and($window())->toBe([$now - 7200, $now, true])
            ->and(json_decode((string) file_get_contents($this->prefsFile), true)['selectedProfile'] ?? null)->toBe('live')
            ->and($switch($now - 880, true))->toBeTrue()
            ->and($window())->toBe([$now - 7200, $now, true])
        ;

        // A profile that stopped recording: the window ends at its newest data and stays there.
        expect($switch($now - 86400, true))->toBeTrue()
            ->and($window())->toBe([$now - 86400 - 7200, $now - 86400, false])
        ;

        $c->getSignal('selected_profile')?->setValue('nope', broadcast: false);
        expect(RangeActions::changeProfile($c, $now))->toBeFalse();
    });

    test('in filtered mode a range op re-counts the capture files a build would read, by name and size only', function (): void {
        $root = sys_get_temp_dir() . '/nfsen-range-capture-' . bin2hex(random_bytes(4));
        $now = time();
        foreach ([2, 3, 4, 30] as $intervalsAgo) {
            $at = (new DateTimeImmutable('@' . (intdiv($now, 300) - $intervalsAgo) * 300))->setTimezone(Config::nfcapdTimezone());
            $dir = $root . '/live/gw/' . $at->format('Y/m/d');
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            file_put_contents($dir . '/nfcapd.' . $at->format('YmdHi'), str_repeat('x', 100));
        }
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [80], 'max_stats_window' => 0],
            'nfdump' => ['profiles-data' => $root, 'profile' => 'live'],
        ]);

        $c = $this->c;
        OverviewPage::signals($c);
        $c->getSignal('graph_mode')?->setValue('filtered', broadcast: false);
        $c->getSignal('graph_sources')?->setValue(['gw'], broadcast: false);
        $counts = static function (array $query) use ($c): array {
            $c->setRequestInput($query, []);
            $c->executeAction((string) $c->getAction('set-range')?->id());

            return [$c->getSignal('nfcapd_file_count')?->int(), $c->getSignal('nfcapd_total_bytes')?->int()];
        };

        try {
            expect($counts(['op' => 'preset', 'v' => '1h']))->toBe([3, 300])
                ->and($counts(['op' => 'preset', 'v' => '24h']))->toBe([4, 400])
                ->and(FakeProcessor::$calls)->toBe([])
            ;
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    });
});
