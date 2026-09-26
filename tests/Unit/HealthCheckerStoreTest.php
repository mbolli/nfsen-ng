<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\pages\HealthPage;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migrator;
use mbolli\nfsen_ng\store\TopNRepository;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * The facts of Database::inspect() for an existing, writable WAL store at the current schema.
 *
 * @return array{path: string, exists: bool, writable: bool, journalMode: string, schemaVersion: int, sizeBytes: int, error: string}
 */
function storeFacts(array $overrides = []): array {
    return [
        'path' => '/state/nfsen-ng.sqlite',
        'exists' => true,
        'writable' => true,
        'journalMode' => 'wal',
        'schemaVersion' => Migrator::latestVersion(),
        'sizeBytes' => 8 * 1024 * 1024,
        'error' => '',
        ...$overrides,
    ];
}

/** @return array<string, array{id: string, label: string, status: string, detail: string, group: string, code: bool, hint: string, epoch: int}> */
function storeRows(?array $facts, string $version = '3.46.1', string $path = '/state/nfsen-ng.sqlite'): array {
    $rows = [];
    foreach (HealthChecker::storeChecks($path, $facts, $version) as $row) {
        $rows[$row['id']] = $row;
    }

    return $rows;
}

describe('HealthChecker::storeChecks', function (): void {
    test('a healthy store is all OK, in the Storage (SQLite) group', function (): void {
        $rows = storeRows(storeFacts());

        expect(array_keys($rows))->toBe(['sqlite_file', 'sqlite_writable', 'sqlite_journal', 'sqlite_schema', 'sqlite_size', 'sqlite_version'])
            ->and(array_unique(array_column($rows, 'status')))->toBe(['ok'])
            ->and(array_unique(array_column($rows, 'group')))->toBe([HealthChecker::STORE_GROUP])
            ->and($rows['sqlite_file']['detail'])->toBe('/state/nfsen-ng.sqlite')
            ->and($rows['sqlite_file']['code'])->toBeTrue()
            ->and($rows['sqlite_writable']['detail'])->toBe('Yes')
            ->and($rows['sqlite_journal']['detail'])->toBe('WAL')
            ->and($rows['sqlite_schema']['detail'])->toBe((string) Migrator::latestVersion())
            ->and($rows['sqlite_size']['detail'])->toBe(Estimate::humanBytes(8 * 1024 * 1024))
            ->and($rows['sqlite_version']['detail'])->toBe('3.46.1')
        ;
    });

    test('a read-only store is an error that says why it matters', function (): void {
        $row = storeRows(storeFacts(['writable' => false]))['sqlite_writable'];

        expect($row['status'])->toBe('error')
            ->and($row['detail'])->toStartWith('No, ')
            ->and($row['hint'])->toContain('journal')
        ;
    });

    test('a missing file is a warning where it can be created and an error where it cannot', function (): void {
        $creatable = storeRows(storeFacts(['exists' => false, 'writable' => true, 'journalMode' => '', 'schemaVersion' => 0]));
        $stuck = storeRows(storeFacts(['exists' => false, 'writable' => false, 'journalMode' => '', 'schemaVersion' => 0]));

        expect($creatable['sqlite_file']['status'])->toBe('warning')
            ->and($creatable['sqlite_file']['detail'])->toBe('Not created yet: /state/nfsen-ng.sqlite')
            ->and($stuck['sqlite_file']['status'])->toBe('error')
            ->and($stuck['sqlite_file']['detail'])->toBe('Cannot be created: /state/nfsen-ng.sqlite')
            ->and($stuck['sqlite_file']['hint'])->toContain('NFSEN_STATE_DIR')
            // Nothing to say about a journal, schema or size of a file that is not there.
            ->and($stuck)->not->toHaveKeys(['sqlite_writable', 'sqlite_journal', 'sqlite_schema', 'sqlite_size'])
        ;
    });

    test('no state directory is an error', function (): void {
        $rows = storeRows(null, '3.46.1', '');

        expect($rows['sqlite_file']['status'])->toBe('error')
            ->and($rows['sqlite_file']['detail'])->toBe('The state directory is not configured')
            ->and(array_keys($rows))->toBe(['sqlite_file', 'sqlite_version'])
        ;
    });

    test('the rollback journal is fine, and says why WAL is not used', function (): void {
        $row = storeRows(storeFacts(['journalMode' => 'DELETE']))['sqlite_journal'];

        expect($row['status'])->toBe('ok')
            ->and($row['detail'])->toBe('Rollback journal (DELETE)')
            ->and($row['hint'])->toContain('WAL')
        ;
    });

    test('a schema other than the latest is a warning, newer or older', function (): void {
        $latest = Migrator::latestVersion();
        $newer = storeRows(storeFacts(['schemaVersion' => $latest + 1]))['sqlite_schema'];
        $older = storeRows(storeFacts(['schemaVersion' => $latest - 1]))['sqlite_schema'];

        expect($newer['status'])->toBe('warning')
            ->and($newer['detail'])->toBe(($latest + 1) . ", newer than this nfsen-ng knows ({$latest})")
            ->and($newer['hint'])->toContain('read-only')
            ->and($older['status'])->toBe('warning')
            ->and($older['detail'])->toBe(($latest - 1) . ", expected {$latest}")
        ;
    });

    test('SQLite older than 3.33 or no pdo_sqlite is an error', function (string $version, string $status, string $detail): void {
        $row = storeRows(storeFacts(), $version)['sqlite_version'];

        expect($row['status'])->toBe($status)
            ->and($row['detail'])->toBe($detail)
        ;
    })->with([
        'too old' => ['3.32.3', 'error', '3.32.3. SQLite 3.33 or later is required.'],
        'oldest that works' => ['3.33.0', 'ok', '3.33.0'],
        'no driver' => ['', 'error', 'Not available, pdo_sqlite is not loaded'],
    ]);

    test('an error from inspect() gets its own error row', function (): void {
        $row = storeRows(storeFacts(['error' => 'the -wal file is not checkpointed']))['sqlite_error'] ?? null;

        expect($row)->not->toBeNull()
            ->and($row['status'])->toBe('error')
            ->and($row['detail'])->toBe('the -wal file is not checkpointed')
        ;
    });

    test('no detail or hint carries an em or en dash', function (): void {
        $latest = Migrator::latestVersion();
        foreach ([
            storeRows(null, ''),
            storeRows(storeFacts(['writable' => false, 'journalMode' => 'delete', 'schemaVersion' => $latest + 1, 'error' => 'x']), '3.8.0'),
            storeRows(storeFacts(['exists' => false, 'writable' => false])),
        ] as $rows) {
            foreach ($rows as $id => $row) {
                expect($row['detail'] . ' ' . $row['hint'])->not->toMatch('/[\x{2013}\x{2014}]/u', $id);
            }
        }
    });
});

describe('HealthChecker::journalLabel and plainText', function (): void {
    test('names the journal modes', function (): void {
        expect(HealthChecker::journalLabel('WAL'))->toBe('WAL')
            ->and(HealthChecker::journalLabel('delete'))->toBe('Rollback journal (DELETE)')
            ->and(HealthChecker::journalLabel('TRUNCATE'))->toBe('truncate')
            ->and(HealthChecker::journalLabel(''))->toBe('')
        ;
    });

    test('turns the VictoriaMetrics link into its address and drops other markup', function (): void {
        expect(HealthChecker::plainText('vm:8428 &middot; <a href="http://vm:8428/vmui" target="_blank" rel="noopener">Open VMUI</a>'))
            ->toBe('vm:8428 · http://vm:8428/vmui')
            ->and(HealthChecker::plainText('<strong>Down</strong> &amp; unreachable'))->toBe('Down & unreachable')
            ->and(HealthChecker::plainText('Plain text, left alone'))->toBe('Plain text, left alone')
        ;
    });
});

describe('HealthChecker::diskChecks', function (): void {
    $disk = static fn (string $label, ?float $usedPct, string $path = '/data'): array => [
        'label' => $label, 'path' => $path, 'usedPct' => $usedPct,
        'free' => $usedPct === null ? null : (int) ((100 - $usedPct) * 1024 ** 3),
        'total' => $usedPct === null ? null : 100 * 1024 ** 3,
    ];

    test('turns warning at 85 % and error at 95 %, like the usage bars', function () use ($disk): void {
        $rows = array_column(HealthChecker::diskChecks([
            $disk('Capture', 42.0, '/var/nfdump/profiles-data'),
            $disk('Data', 85.0, '/var/nfsen-ng/data'),
            $disk('State', 95.0, '/var/nfsen-ng/state'),
        ]), null, 'id');

        expect(array_column($rows, 'status', 'id'))->toBe(['disk_capture' => 'ok', 'disk_data' => 'warning', 'disk_state' => 'error'])
            ->and($rows['disk_capture']['label'])->toBe('Disk space: Capture')
            ->and($rows['disk_capture']['detail'])->toBe('42% used, ' . Estimate::humanBytes((int) (58 * 1024 ** 3)) . ' free of ' . Estimate::humanBytes(100 * 1024 ** 3))
            ->and($rows['disk_capture']['hint'])->toBe('')
            ->and($rows['disk_data']['hint'])->toContain('/var/nfsen-ng/data')
            ->and($rows['disk_state']['hint'])->toContain('/var/nfsen-ng/state')
            ->and(array_unique(array_column($rows, 'group')))->toBe([HealthChecker::DISK_GROUP])
            ->and(HealthChecker::diskLevel(84.9))->toBe('')
            ->and(HealthChecker::diskLevel(null))->toBe('')
        ;
        foreach ($rows as $id => $row) {
            expect($row['detail'] . ' ' . $row['hint'])->not->toMatch('/[\x{2013}\x{2014}]/u', $id);
        }
    });

    test('skips what was not measured and names merged filesystems', function () use ($disk): void {
        $rows = HealthChecker::diskChecks([$disk('Capture, Data, State', 10.0), $disk('Data', null, 'remote'), $disk('State', null, '/missing')]);

        expect(array_column($rows, 'id'))->toBe(['disk_capture_data_state'])
            ->and($rows[0]['label'])->toBe('Disk space: Capture, Data, State')
        ;
    });

    test('a full filesystem raises the level the sidebar shows', function () use ($disk): void {
        expect(HealthPage::levelOf(HealthChecker::diskChecks([$disk('Capture', 96.0)])))->toBe('error')
            ->and(HealthPage::levelOf(HealthChecker::diskChecks([$disk('Capture', 90.0)])))->toBe('warning')
            ->and(HealthPage::levelOf(HealthChecker::diskChecks([$disk('Capture', 50.0)])))->toBe('ok')
        ;
    });
});

describe('HealthChecker::run without a writable state directory', function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir() . '/nfsen-ng-store-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        $this->dbBefore = isset(Config::$db) ? Config::$db : null;
        $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : '';

        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gateway'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => $this->root . '/profiles-data', 'profile' => 'live', 'max-processes' => 2],
            'log' => ['priority' => LOG_ERR],
        ]);
        $db = $this->createStub(Datasource::class);
        $db->method('last_update')->willReturn(0);
        $db->method('healthChecks')->willReturnCallback(static fn (string $group): array => [[
            'id' => 'vm_config', 'label' => 'VictoriaMetrics', 'status' => 'ok', 'group' => $group, 'code' => false, 'epoch' => 0,
            'detail' => 'vm:8428 &middot; <a href="http://vm:8428/vmui">Open VMUI</a>', 'hint' => '<em>none</em>',
        ]]);
        Config::$db = $db;
        // A regular file where the state directory should be: not even root can create the store.
        touch($this->root . '/state');
        Config::$stateDir = $this->root . '/state/sub';
        putenv('NFSEN_SKIP_DAEMON');
    });

    afterEach(function (): void {
        putenv('NFSEN_SKIP_DAEMON');
        unlink($this->root . '/state');
        rmdir($this->root);
        if ($this->settingsBefore instanceof Settings) {
            Config::$settings = $this->settingsBefore;
        }
        if ($this->dbBefore instanceof Datasource) {
            Config::$db = $this->dbBefore;
        }
        Config::$stateDir = $this->stateDirBefore;
    });

    test('still answers, with the store as an error and datasource rows as plain text (MCP status)', function (): void {
        $byId = array_column(HealthChecker::run(true), null, 'id');

        expect($byId['sqlite_file']['status'])->toBe('error')
            ->and($byId['sqlite_file']['detail'])->toBe('Cannot be created: ' . $this->root . '/state/sub/' . Database::FILENAME)
            ->and($byId['vm_config']['detail'])->toBe('vm:8428 · http://vm:8428/vmui')
            ->and($byId['vm_config']['hint'])->toBe('none')
            // Without NFSEN_SKIP_DAEMON, true only means the caller cannot see the daemon.
            ->and($byId['daemon_status']['detail'])->toBe('Not visible to the status tool')
            ->and($byId['daemon_status']['hint'])->toBe('The Health page shows the state of the import daemon.')
        ;
    });

    test('measures the capture filesystem in the Disk space group', function (): void {
        mkdir($this->root . '/profiles-data');
        $rows = array_values(array_filter(HealthChecker::run(true), static fn (array $c): bool => $c['group'] === HealthChecker::DISK_GROUP));
        rmdir($this->root . '/profiles-data');

        // The data directory may share the filesystem, which merges its label into this row.
        expect($rows)->not->toBeEmpty()
            ->and($rows[0]['id'])->toStartWith('disk_capture')
            ->and($rows[0]['label'])->toStartWith('Disk space: Capture')
            ->and($rows[0]['detail'])->toMatch('/^\d+% used, .+ free of .+$/')
        ;
    });

    test('warns when nfcapd file names drift hours from their write time', function (): void {
        $check = function (int $nameTs, int $writtenAt): array {
            $name = (new DateTimeImmutable('@' . $nameTs))->setTimezone(Config::nfcapdTimezone());
            $dir = $this->root . '/profiles-data/live/gateway/' . $name->format('Y/m/d');
            @mkdir($dir, 0o777, true);
            $file = $dir . '/nfcapd.' . $name->format('YmdHi');
            touch($file, $writtenAt);
            $row = array_column(HealthChecker::run(true), null, 'id')['tz_plausibility'];
            exec('rm -rf ' . escapeshellarg($this->root . '/profiles-data'));

            return $row;
        };
        $interval = intdiv(time(), 300) * 300 - 300;

        expect($check($interval, $interval + 300)['status'])->toBe('ok')
            ->and($check($interval - 7200, $interval + 300))->toMatchArray(['status' => 'warning'])
            ->and($check($interval - 7200, $interval + 300)['detail'])->toStartWith('File names are 2 h behind their write time')
        ;
    });

    test('a render reads a stale cache as it is and the refresh lands afterwards', function (): void {
        $app = new Via(new ViaConfig());
        $app->setGlobalState(HealthPage::CACHE, ['ts' => time() - 3600, 'checks' => [], 'level' => 'warning', 'metrics' => null]);
        $seen = '';
        Coroutine::run(static function () use ($app, &$seen): void {
            $seen = HealthPage::level($app, time());
        });
        $cache = $app->globalState(HealthPage::CACHE);

        // The store cannot be created here, so the refreshed checks carry an error.
        expect($seen)->toBe('warning')
            ->and($cache['ts'])->toBeGreaterThan(time() - 60)
            ->and($cache['level'])->toBe('error')
            ->and($cache['metrics'])->toBeNull()
        ;
    });

    test('invalidate() keeps the checks on screen but makes both parts due', function (): void {
        $app = new Via(new ViaConfig());
        $metrics = ['ts' => time(), 'sources' => [], 'disks' => [], 'versions' => ['php' => '', 'openswoole' => '', 'sqlite' => '', 'nfdump' => ''], 'journalMode' => 'wal'];
        $app->setGlobalState(HealthPage::CACHE, ['ts' => time(), 'checks' => [], 'level' => 'warning', 'metrics' => $metrics]);
        HealthPage::invalidate($app);
        $cache = $app->globalState(HealthPage::CACHE);

        expect($cache['ts'])->toBe(0)
            ->and($cache['level'])->toBe('warning')
            ->and($cache['metrics']['ts'])->toBe(0)
            ->and($cache['metrics']['journalMode'])->toBe('wal')
            // Outside a coroutine the next read refreshes inline.
            ->and(HealthPage::level($app, time()))->toBe('error')
        ;
    });

    test('names NFSEN_SKIP_DAEMON when it is what disabled the daemon', function (): void {
        putenv('NFSEN_SKIP_DAEMON=1');
        $row = array_column(HealthChecker::run(true), null, 'id')['daemon_status'];

        expect($row['detail'])->toBe('Disabled')
            ->and($row['hint'])->toContain('NFSEN_SKIP_DAEMON')
            ->and($row['hint'])->not->toContain('this page')
        ;
    });
});

describe('HealthPage::topnOff', function (): void {
    test('says the collector is off where it never booted', function (): void {
        $app = new Via(new ViaConfig());

        expect(HealthPage::topnOff($app, true))->toBe('Off: the configuration did not load.');

        $app->setGlobalState('daemon_disabled', true);
        expect(HealthPage::topnOff($app, false))->toBe('Off: the import daemon is disabled (NFSEN_SKIP_DAEMON).');
    });

    test('says so when boot() failed, and nothing once the collector runs', function (): void {
        $settingsBefore = Config::$settings;
        Config::$settings = Settings::fromArray(['general' => ['sources' => ['gw']]]);
        Database::useShared(Database::open(':memory:'));
        TopNCollector::reset();
        $app = new Via(new ViaConfig());

        try {
            $unbooted = HealthPage::topnOff($app, false);
            TopNCollector::start(new TopNRepository(Database::shared()), 31, time());

            expect($unbooted)->toBe('Off: the collector did not start, see the log.')
                ->and(HealthPage::topnOff($app, false))->toBe('')
            ;
        } finally {
            TopNCollector::reset();
            Database::resetShared();
            Config::$settings = $settingsBefore;
        }
    });
});
