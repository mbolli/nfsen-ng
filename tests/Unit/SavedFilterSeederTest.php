<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\SavedFilterRepository;
use mbolli\nfsen_ng\store\SavedFilterSeeder;

/** @param list<string> $filters */
function seederTestWritePreferences(string $path, array $filters): void {
    file_put_contents($path, json_encode(['defaultView' => 'graphs', 'filters' => $filters], JSON_THROW_ON_ERROR));
}

/** @return array<string, string> expression => origin */
function seederTestOrigins(Database $db): array {
    return array_column($db->all('SELECT expression, origin FROM saved_filters ORDER BY id'), 'origin', 'expression');
}

beforeEach(function (): void {
    $this->deploymentFiltersBefore = Config::$deploymentFilters;
    $this->prefsFileBefore = isset(Config::$prefsFile) ? Config::$prefsFile : null;
    $this->dir = sys_get_temp_dir() . '/nfsen-seeder-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0o777, true);
    Config::$deploymentFilters = [];
    Config::$prefsFile = $this->dir . '/preferences.json';
    $this->db = Database::open(':memory:');
    // Unreadable preferences are logged at LOG_WARNING, which Debug echoes on the CLI.
    ob_start();
});

afterEach(function (): void {
    ob_end_clean();
    Config::$deploymentFilters = $this->deploymentFiltersBefore;
    Config::$prefsFile = $this->prefsFileBefore ?? '';
    removeTree($this->dir);
});

describe('preference presets', function (): void {
    test('are imported once with origin preference', function (): void {
        seederTestWritePreferences(Config::$prefsFile, ['proto tcp', "dst port 53\n", '']);

        SavedFilterSeeder::seed($this->db);
        seederTestWritePreferences(Config::$prefsFile, ['proto tcp', 'proto udp']);
        SavedFilterSeeder::seed($this->db);

        expect(seederTestOrigins($this->db))->toBe(['proto tcp' => 'preference', 'dst port 53' => 'preference'])
            ->and($this->db->metaGet(SavedFilterSeeder::META_PREFERENCES_MIGRATED))->toBe('1')
            ->and(array_column($this->db->all('SELECT name FROM saved_filters ORDER BY id'), 'name'))->toBe(['proto tcp', 'dst port 53'])
        ;
    });

    test('a deleted one does not come back', function (): void {
        seederTestWritePreferences(Config::$prefsFile, ['proto tcp']);
        SavedFilterSeeder::seed($this->db);
        $this->db->exec('DELETE FROM saved_filters');

        SavedFilterSeeder::seed($this->db);

        expect(seederTestOrigins($this->db))->toBe([]);
    });

    test('without a preferences.json there is nothing to import and the migration is done', function (): void {
        SavedFilterSeeder::seed($this->db);

        expect(seederTestOrigins($this->db))->toBe([])
            ->and($this->db->metaGet(SavedFilterSeeder::META_PREFERENCES_MIGRATED))->toBe('1')
        ;
    });

    test('an unreadable preferences.json is retried on the next seed', function (): void {
        file_put_contents(Config::$prefsFile, '{"filters": ["proto tcp"');

        SavedFilterSeeder::seed($this->db);
        $migratedEarly = $this->db->metaGet(SavedFilterSeeder::META_PREFERENCES_MIGRATED);
        seederTestWritePreferences(Config::$prefsFile, ['proto tcp']);
        SavedFilterSeeder::seed($this->db);

        expect($migratedEarly)->toBeNull()
            ->and(seederTestOrigins($this->db))->toBe(['proto tcp' => 'preference'])
        ;
    });

    test('a retried migration does not bring back a deployment preset deleted meanwhile', function (): void {
        Config::$deploymentFilters = ['proto tcp', 'dst port 22'];
        file_put_contents(Config::$prefsFile, '{"filters": ["proto tcp"');
        SavedFilterSeeder::seed($this->db);
        $this->db->exec("DELETE FROM saved_filters WHERE expression = 'proto tcp'");

        seederTestWritePreferences(Config::$prefsFile, [' proto  tcp', 'dst port 22', 'dst port 443']);
        SavedFilterSeeder::seed($this->db);

        expect(seederTestOrigins($this->db))->toBe(['dst port 22' => 'deployment', 'dst port 443' => 'preference'])
            ->and($this->db->metaGet(SavedFilterSeeder::META_PREFERENCES_MIGRATED))->toBe('1')
        ;
    });
});

describe('deployment presets', function (): void {
    test('are imported with origin deployment and remembered as seen', function (): void {
        Config::$deploymentFilters = ['proto tcp', ' dst  port 22 ', '', 'proto tcp'];

        SavedFilterSeeder::seed($this->db);

        expect(seederTestOrigins($this->db))->toBe(['proto tcp' => 'deployment', 'dst  port 22' => 'deployment'])
            ->and(SavedFilterSeeder::seenDeploymentKeys($this->db))->toBe(['proto tcp', 'dst port 22'])
        ;
    });

    test('a deleted one stays deleted and a new one appears once', function (): void {
        Config::$deploymentFilters = ['proto tcp', 'dst port 22'];
        SavedFilterSeeder::seed($this->db);
        $this->db->exec("DELETE FROM saved_filters WHERE expression = 'proto tcp'");

        Config::$deploymentFilters = ['proto tcp', 'dst port 22', 'dst port 3389'];
        SavedFilterSeeder::seed($this->db);
        $this->db->exec("DELETE FROM saved_filters WHERE expression = 'dst port 3389'");
        SavedFilterSeeder::seed($this->db);

        expect(seederTestOrigins($this->db))->toBe(['dst port 22' => 'deployment'])
            ->and(SavedFilterSeeder::seenDeploymentKeys($this->db))->toBe(['proto tcp', 'dst port 22', 'dst port 3389'])
        ;
    });

    test('a preset that matches a filter the user saved keeps the user row and is marked seen', function (): void {
        new SavedFilterRepository($this->db)->create('Mine', 'proto  tcp');
        Config::$deploymentFilters = ['proto tcp'];

        SavedFilterSeeder::seed($this->db);

        expect($this->db->all('SELECT name, origin FROM saved_filters'))->toBe([['name' => 'Mine', 'origin' => 'user']])
            ->and(SavedFilterSeeder::seenDeploymentKeys($this->db))->toBe(['proto tcp'])
        ;
    });

    test('a garbled seen list reads as empty', function (): void {
        $this->db->metaSet(SavedFilterSeeder::META_DEPLOYMENT_SEEN, '{"not": "a list"');

        expect(SavedFilterSeeder::seenDeploymentKeys($this->db))->toBe([]);
    });
});

describe('a preferences.json with its own filters', function (): void {
    test('imports the deployment presets as deployment and the others as preference', function (): void {
        // The old Preferences form saved the merged list, so a deployment preset can be in both.
        Config::$deploymentFilters = ['proto tcp', 'dst port 22'];
        seederTestWritePreferences(Config::$prefsFile, ['proto tcp', 'dst port 22', 'dst port 443', 'flags R']);

        SavedFilterSeeder::seed($this->db);

        expect(seederTestOrigins($this->db))->toBe([
            'proto tcp' => 'deployment',
            'dst port 22' => 'deployment',
            'dst port 443' => 'preference',
            'flags R' => 'preference',
        ])
            ->and(SavedFilterSeeder::seenDeploymentKeys($this->db))->toBe(['proto tcp', 'dst port 22'])
        ;
    });

    test('Config::initialize() takes the deployment presets from before the preferences overlay', function (): void {
        $settingsFile = $this->dir . '/settings.php';
        file_put_contents($settingsFile, '<?php $nfsen_config = ' . var_export([
            'general' => [
                'ports' => [80],
                'sources' => [],
                'filters' => ['proto tcp', 'dst port 22'],
                'db' => 'VictoriaMetrics',
                'processor' => 'NfDump',
            ],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->dir . '/profiles', 'profile' => 'live', 'max-processes' => 1],
            'log' => ['priority' => LOG_ERR],
        ], true) . ';');
        mkdir($this->dir . '/state');
        seederTestWritePreferences($this->dir . '/state/preferences.json', ['dst port 443', 'proto tcp']);

        $script = <<<'PHP'
            use mbolli\nfsen_ng\common\Config;
            use mbolli\nfsen_ng\common\Debug;
            use mbolli\nfsen_ng\store\Database;
            use mbolli\nfsen_ng\store\SavedFilterRepository;

            require getenv('NFSEN_TEST_ROOT') . '/vendor/autoload.php';
            Debug::getInstance()->setDebug(false);
            Config::initialize();
            echo json_encode([
                'deployment' => Config::$deploymentFilters,
                'settings' => Config::$settings->filters,
                'saved' => array_column(new SavedFilterRepository(Database::shared())->list(), 'origin', 'expression'),
            ]);
            PHP;
        $env = array_filter(getenv(), static fn (string $name): bool => !str_starts_with($name, 'NFSEN_'), ARRAY_FILTER_USE_KEY);
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            ...$env,
            'NFSEN_TEST_ROOT' => dirname(__DIR__, 2),
            'NFSEN_SETTINGS_FILE' => $settingsFile,
            'NFSEN_STATE_DIR' => $this->dir . '/state',
        ]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0, $err . $out);
        $result = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        expect($result['deployment'])->toBe(['proto tcp', 'dst port 22'])
            ->and($result['settings'])->toBe(['dst port 443', 'proto tcp'])
            ->and($result['saved'])->toEqualCanonicalizing([
                'proto tcp' => 'deployment',
                'dst port 22' => 'deployment',
                'dst port 443' => 'preference',
            ])
        ;
    });
});
