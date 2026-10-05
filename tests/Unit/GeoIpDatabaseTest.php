<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\GeoIpDatabase;
use mbolli\nfsen_ng\common\Settings;
use OpenSwoole\Coroutine;

/** A GeoLite2-City record as the reader decodes it. */
const GEOIP_TEST_CITY = [
    'city' => ['geoname_id' => 2657896, 'names' => ['de' => 'Zürich', 'en' => 'Zurich']],
    'continent' => ['code' => 'EU', 'geoname_id' => 6255148, 'names' => ['en' => 'Europe']],
    'country' => ['geoname_id' => 2658434, 'is_in_european_union' => false, 'iso_code' => 'CH', 'names' => ['de' => 'Schweiz', 'en' => 'Switzerland']],
    'location' => ['accuracy_radius' => 20, 'latitude' => 47.3682, 'longitude' => 8.5671, 'time_zone' => 'Europe/Zurich'],
    'postal' => ['code' => '8001'],
    'registered_country' => ['geoname_id' => 2658434, 'iso_code' => 'CH', 'names' => ['en' => 'Switzerland']],
    'subdivisions' => [['geoname_id' => 2657895, 'iso_code' => 'ZH', 'names' => ['en' => 'Zurich']]],
];

/** A GeoLite2-Country record: no city, subdivision or location. */
const GEOIP_TEST_COUNTRY = [
    'continent' => ['code' => 'EU', 'geoname_id' => 6255148, 'names' => ['en' => 'Europe']],
    'country' => ['geoname_id' => 2921044, 'iso_code' => 'DE', 'names' => ['en' => 'Germany']],
    'registered_country' => ['geoname_id' => 2921044, 'iso_code' => 'DE', 'names' => ['en' => 'Germany']],
];

/** MaxMind DB control byte(s) for a field of the given type and payload size. */
function geoIpTestControl(int $type, int $size): string {
    $extended = $type > 7;
    $head = ($extended ? 0 : $type) << 5;
    [$sizeBits, $sizeBytes] = match (true) {
        $size < 29 => [$size, ''],
        $size < 285 => [29, chr($size - 29)],
        $size < 65821 => [30, pack('n', $size - 285)],
        default => [31, substr(pack('N', $size - 65821), 1)],
    };

    return chr($head | $sizeBits) . ($extended ? chr($type - 7) : '') . $sizeBytes;
}

/** Encodes a value in the MaxMind DB data format (maps, arrays, strings, doubles, unsigned ints, booleans). */
function geoIpTestEncode(mixed $value): string {
    if (is_array($value) && $value !== [] && array_is_list($value)) {
        return geoIpTestControl(11, count($value)) . implode('', array_map(geoIpTestEncode(...), $value));
    }
    if (is_array($value)) {
        $pairs = '';
        foreach ($value as $key => $item) {
            $pairs .= geoIpTestEncode((string) $key) . geoIpTestEncode($item);
        }

        return geoIpTestControl(7, count($value)) . $pairs;
    }
    if (is_string($value)) {
        return geoIpTestControl(2, strlen($value)) . $value;
    }
    if (is_float($value)) {
        return geoIpTestControl(3, 8) . pack('E', $value);
    }
    if (is_bool($value)) {
        return geoIpTestControl(14, $value ? 1 : 0);
    }
    if (is_int($value) && $value >= 0) {
        $bytes = ltrim(pack('J', $value), "\0");
        $type = $value <= 0xFFFF ? 5 : ($value <= 0xFFFFFFFF ? 6 : 9);

        return geoIpTestControl($type, strlen($bytes)) . $bytes;
    }

    throw new InvalidArgumentException('The test writer cannot encode ' . get_debug_type($value));
}

/**
 * Writes an IPv4 MaxMind DB: 0.0.0.0/2 holds $low, 64.0.0.0/2 holds $high, 128.0.0.0/1 is empty.
 *
 * @param array<string, mixed> $low
 * @param array<string, mixed> $high
 */
function geoIpTestWrite(string $path, array $low, array $high, string $type = 'GeoLite2-City', int $buildEpoch = 1758585600): void {
    $nodeCount = 2;
    $lowData = geoIpTestEncode($low);
    $highData = geoIpTestEncode($high);
    $record = static fn (int $value): string => substr(pack('N', $value), 1);
    $pointer = static fn (int $offset): int => $nodeCount + 16 + $offset;

    $tree = $record(1) . $record($nodeCount) . $record($pointer(0)) . $record($pointer(strlen($lowData)));
    $metadata = geoIpTestEncode([
        'binary_format_major_version' => 2,
        'binary_format_minor_version' => 0,
        'build_epoch' => $buildEpoch,
        'database_type' => $type,
        'description' => ['en' => 'nfsen-ng test database'],
        'ip_version' => 4,
        'languages' => ['en'],
        'node_count' => $nodeCount,
        'record_size' => 24,
    ]);

    file_put_contents($path, $tree . str_repeat("\0", 16) . $lowData . $highData . "\xAB\xCD\xEFMaxMind.com" . $metadata);
}

/** Runs $check with the effective uid of nobody when the suite runs as root, which reads any file. */
function geoIpTestAsNobody(Closure $check): mixed {
    $asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    if ($asRoot) {
        posix_seteuid(65534);
    }

    try {
        return $check();
    } finally {
        if ($asRoot) {
            posix_seteuid(0);
        }
    }
}

function geoIpTestRemove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        chmod($path, 0o700);
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            geoIpTestRemove($path . '/' . $entry);
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/**
 * @param array<string, mixed> $record
 */
function geoIpTestFake(array $record): GeoIpDatabase {
    return new GeoIpDatabase('/fake/GeoIP2.mmdb', static fn (string $ip): array => $record, ['type' => 'Fake', 'buildEpoch' => 0, 'ipVersion' => 6]);
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->dir = sys_get_temp_dir() . '/nfsen-geoip-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->configure = static function (string $path): void {
        Config::$settings = Settings::fromArray(mockSettings())->withGeoipDb($path);
    };
    GeoIpDatabase::resetShared();
});

afterEach(function (): void {
    GeoIpDatabase::resetShared();
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    } elseif (isset(Config::$settings)) {
        Config::$settings = Config::$settings->withGeoipDb('');
    }
    geoIpTestRemove($this->dir);
});

describe('GeoIpDatabase::status()', function (): void {
    test('reports nothing configured when NFSEN_GEOIP_DB is unset', function (): void {
        ($this->configure)('');

        expect(GeoIpDatabase::shared())->toBeNull()
            ->and(GeoIpDatabase::status())->toBe([
                'path' => '', 'readable' => false, 'type' => '', 'buildEpoch' => 0, 'ipVersion' => 0, 'error' => '',
            ])
        ;
    });

    test('reports a missing file', function (): void {
        ($this->configure)($this->dir . '/GeoLite2-City.mmdb');

        expect(GeoIpDatabase::shared())->toBeNull()
            ->and(GeoIpDatabase::status())->toBe([
                'path' => $this->dir . '/GeoLite2-City.mmdb', 'readable' => false, 'type' => '', 'buildEpoch' => 0, 'ipVersion' => 0, 'error' => 'File not found.',
            ])
        ;
    });

    test('reports a file in a missing directory as not found', function (): void {
        ($this->configure)($this->dir . '/GeoIP/GeoLite2-City.mmdb');

        expect(geoIpTestAsNobody(static fn (): array => GeoIpDatabase::status())['error'])->toBe('File not found.');
    });

    test('reports a file the process may not read', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        chmod($path, 0o000);
        ($this->configure)($path);

        [$db, $status] = geoIpTestAsNobody(static fn (): array => [GeoIpDatabase::shared(), GeoIpDatabase::status()]);

        expect($db)->toBeNull()
            ->and($status['path'])->toBe($path)
            ->and($status['readable'])->toBeFalse()
            ->and($status['error'])->toBe('The file is not readable by the nfsen-ng process (check its permissions).')
        ;
    });

    test('reports a directory on the path the process may not enter', function (): void {
        $locked = $this->dir . '/GeoIP';
        mkdir($locked . '/sub', 0o755, true);
        geoIpTestWrite($locked . '/GeoLite2-City.mmdb', GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        chmod($locked, 0o600);
        $message = 'The directory ' . $locked . ' is not accessible to the nfsen-ng process (check its permissions).';

        [$db, $status, $nestedStatus] = geoIpTestAsNobody(function () use ($locked): array {
            ($this->configure)($locked . '/GeoLite2-City.mmdb');
            $db = GeoIpDatabase::shared();
            $status = GeoIpDatabase::status();
            ($this->configure)($locked . '/sub/GeoLite2-City.mmdb');

            return [$db, $status, GeoIpDatabase::status()];
        });

        expect($db)->toBeNull()
            ->and($status['path'])->toBe($locked . '/GeoLite2-City.mmdb')
            ->and($status['readable'])->toBeFalse()
            ->and($status['error'])->toBe($message)
            ->and($nestedStatus['error'])->toBe($message)
        ;
    });

    test('follows a directory between missing, locked and open without a reset', function (): void {
        $locked = $this->dir . '/GeoIP';
        ($this->configure)($locked . '/GeoLite2-City.mmdb');
        $error = static fn (): string => geoIpTestAsNobody(static fn (): array => [GeoIpDatabase::shared(), GeoIpDatabase::status()])[1]['error'];

        $missing = $error();
        mkdir($locked, 0o600);
        chmod($locked, 0o600);
        $blocked = $error();
        chmod($locked, 0o755);
        $open = $error();

        expect($missing)->toBe('File not found.')
            ->and($blocked)->toBe('The directory ' . $locked . ' is not accessible to the nfsen-ng process (check its permissions).')
            ->and($open)->toBe('File not found.')
        ;
    });

    test('reports a directory', function (): void {
        mkdir($this->dir . '/sub');
        ($this->configure)($this->dir . '/sub');

        expect(GeoIpDatabase::shared())->toBeNull()
            ->and(GeoIpDatabase::status()['error'])->toContain('directory')
        ;
    });

    test('reports a file that is not a MaxMind database', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        file_put_contents($path, str_repeat('not a database ', 100));
        ($this->configure)($path);

        $status = GeoIpDatabase::status();

        expect(GeoIpDatabase::shared())->toBeNull()
            ->and($status['path'])->toBe($path)
            ->and($status['readable'])->toBeFalse()
            ->and($status['error'])->toContain('valid MaxMind DB')
        ;
    });

    test('reports an empty file as not a MaxMind database', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        touch($path);
        ($this->configure)($path);

        expect(GeoIpDatabase::status()['error'])->toContain('valid MaxMind DB');
    });

    test('reports the type, build date and IP version of an open database', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        ($this->configure)($path);

        expect(GeoIpDatabase::status())->toBe([
            'path' => $path, 'readable' => true, 'type' => 'GeoLite2-City', 'buildEpoch' => 1758585600, 'ipVersion' => 4, 'error' => '',
        ]);
    });

    test('reports an injected instance and nothing when null is injected', function (): void {
        ($this->configure)($this->dir . '/missing.mmdb');

        GeoIpDatabase::useShared(geoIpTestFake(GEOIP_TEST_CITY));
        expect(GeoIpDatabase::status())->toBe([
            'path' => '/fake/GeoIP2.mmdb', 'readable' => true, 'type' => 'Fake', 'buildEpoch' => 0, 'ipVersion' => 6, 'error' => '',
        ]);

        GeoIpDatabase::useShared(null);
        expect(GeoIpDatabase::shared())->toBeNull()
            ->and(GeoIpDatabase::status()['path'])->toBe('')
            ->and(GeoIpDatabase::status()['error'])->toBe('')
        ;
    });
});

describe('GeoIpDatabase::shared()', function (): void {
    test('opens the file once and keeps it while the file is unchanged', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        ($this->configure)($path);

        $first = GeoIpDatabase::shared();

        expect($first)->toBeInstanceOf(GeoIpDatabase::class)
            ->and(GeoIpDatabase::shared())->toBe($first)
        ;
    });

    test('reopens the database when an update replaces the file', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        ($this->configure)($path);
        $first = GeoIpDatabase::shared();

        // geoipupdate writes a temporary file and renames it over the old one.
        geoIpTestWrite($path . '.tmp', GEOIP_TEST_COUNTRY, GEOIP_TEST_CITY, 'GeoLite2-Country', 1759190400);
        rename($path . '.tmp', $path);
        $second = GeoIpDatabase::shared();

        expect($second)->not->toBe($first)
            ->and($second?->metadata)->toBe(['type' => 'GeoLite2-Country', 'buildEpoch' => 1759190400, 'ipVersion' => 4])
            ->and($second?->lookup('8.8.8.8')['countryCode'] ?? null)->toBe('DE')
            ->and($first?->lookup('8.8.8.8')['countryCode'] ?? null)->toBe('CH')
        ;
    });

    test('reopens the database when its mtime changes', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        touch($path, time() - 60);
        ($this->configure)($path);
        $first = GeoIpDatabase::shared();

        touch($path, time());

        expect(GeoIpDatabase::shared())->toBeInstanceOf(GeoIpDatabase::class)->not->toBe($first);
    });

    test('opens a file that appears after a failed attempt', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        ($this->configure)($path);
        expect(GeoIpDatabase::shared())->toBeNull();

        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);

        expect(GeoIpDatabase::shared())->toBeInstanceOf(GeoIpDatabase::class)
            ->and(GeoIpDatabase::status()['error'])->toBe('')
        ;
    });

    test('forgets the database when the path is cleared', function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        ($this->configure)($path);
        expect(GeoIpDatabase::shared())->not->toBeNull();

        ($this->configure)('');

        expect(GeoIpDatabase::shared())->toBeNull();
    });
});

describe('GeoIpDatabase::lookup() on a database file', function (): void {
    beforeEach(function (): void {
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        $this->db = GeoIpDatabase::open($path);
    });

    test('reads a City record', function (): void {
        expect($this->db->lookup('1.1.1.1'))->toBe([
            'country' => 'Switzerland',
            'countryCode' => 'CH',
            'region' => 'Zurich',
            'city' => 'Zurich',
            'latitude' => 47.3682,
            'longitude' => 8.5671,
            'asn' => '',
            'org' => '',
        ]);
    });

    test('reads a Country record, leaving the place fields empty', function (): void {
        expect($this->db->lookup('81.2.69.160'))->toBe([
            'country' => 'Germany',
            'countryCode' => 'DE',
            'region' => '',
            'city' => '',
            'latitude' => null,
            'longitude' => null,
            'asn' => '',
            'org' => '',
        ]);
    });

    test('returns null where the database has no entry', function (): void {
        expect($this->db->lookup('200.1.1.1'))->toBeNull();
    });

    test('returns null for an IPv6 address in an IPv4-only database', function (): void {
        expect($this->db->lookup('2001:db8::1'))->toBeNull();
    });

    test('returns null for something that is not an address', function (): void {
        expect($this->db->lookup('example.com'))->toBeNull()
            ->and($this->db->lookup(''))->toBeNull()
        ;
    });
});

describe('GeoIpDatabase::lookup() record layouts', function (): void {
    test('reads an ASN database', function (): void {
        $db = geoIpTestFake(['autonomous_system_number' => 13335, 'autonomous_system_organization' => 'CLOUDFLARENET']);

        expect($db->lookup('1.1.1.1'))->toBe([
            'country' => '', 'countryCode' => '', 'region' => '', 'city' => '',
            'latitude' => null, 'longitude' => null, 'asn' => 'AS13335', 'org' => 'CLOUDFLARENET',
        ]);
    });

    test('reads the AS and the ISP from Enterprise traits, preferring the AS organisation', function (): void {
        $db = geoIpTestFake(GEOIP_TEST_CITY + ['traits' => [
            'autonomous_system_number' => 559,
            'autonomous_system_organization' => 'SWITCH',
            'isp' => 'Switch Isp',
        ]]);

        expect($db->lookup('1.1.1.1'))->toMatchArray(['asn' => 'AS559', 'org' => 'SWITCH', 'city' => 'Zurich']);
    });

    test('falls back to the organisation, then the ISP, when there is no AS organisation', function (): void {
        expect(geoIpTestFake(['isp' => 'Example Telecom', 'organization' => 'Example Hosting'])->lookup('1.1.1.1')['org'] ?? null)->toBe('Example Hosting')
            ->and(geoIpTestFake(['isp' => 'Example Telecom'])->lookup('1.1.1.1')['org'] ?? null)->toBe('Example Telecom')
        ;
    });

    test('uses the registered country when the record has no country', function (): void {
        $db = geoIpTestFake(['registered_country' => ['iso_code' => 'us', 'names' => ['en' => 'United States']]]);

        expect($db->lookup('1.1.1.1'))->toMatchArray(['country' => 'United States', 'countryCode' => 'US']);
    });

    test('uses the first name when there is no English one', function (): void {
        $db = geoIpTestFake(['country' => ['iso_code' => 'JP', 'names' => ['ja' => '日本']]]);

        expect($db->lookup('1.1.1.1')['country'] ?? null)->toBe('日本');
    });

    test('returns null for a record with no location, country or AS', function (): void {
        $db = geoIpTestFake(['is_anonymous' => true, 'is_public_proxy' => false]);

        expect($db->lookup('1.1.1.1'))->toBeNull();
    });

    test('returns null when the reader has no record', function (): void {
        $db = new GeoIpDatabase('/fake.mmdb', static fn (string $ip): mixed => null, ['type' => 'Fake', 'buildEpoch' => 0, 'ipVersion' => 6]);

        expect($db->lookup('1.1.1.1'))->toBeNull();
    });

    test('lets a reader failure through', function (): void {
        $db = new GeoIpDatabase('/fake.mmdb', static fn (string $ip): never => throw new RuntimeException('corrupt'), ['type' => 'Fake', 'buildEpoch' => 0, 'ipVersion' => 6]);

        expect(fn () => $db->lookup('1.1.1.1'))->toThrow(RuntimeException::class, 'corrupt');
    });

    test('runs one lookup at a time across coroutines', function (): void {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('needs the openswoole extension');
        }
        $active = 0;
        $most = 0;
        $db = new GeoIpDatabase('/fake.mmdb', static function (string $ip) use (&$active, &$most): array {
            $most = max($most, ++$active);
            Coroutine::usleep(2000);
            --$active;

            return GEOIP_TEST_COUNTRY;
        }, ['type' => 'Fake', 'buildEpoch' => 0, 'ipVersion' => 6]);

        $answers = [];
        Coroutine::run(static function () use ($db, &$answers): void {
            for ($i = 0; $i < 3; ++$i) {
                Coroutine::create(static function () use ($db, &$answers): void {
                    $answers[] = $db->lookup('1.1.1.1')['countryCode'] ?? null;
                });
            }
        });

        expect($most)->toBe(1)
            ->and($answers)->toBe(['DE', 'DE', 'DE'])
        ;
    });

    test('serves concurrent coroutines from one database file under the file hook', function (): void {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('needs the openswoole extension');
        }
        $path = $this->dir . '/GeoLite2-City.mmdb';
        geoIpTestWrite($path, GEOIP_TEST_CITY, GEOIP_TEST_COUNTRY);
        $db = GeoIpDatabase::open($path);

        // Coroutine::run() turns on every runtime hook, so each read the reader makes yields.
        $answers = [];
        Coroutine::run(static function () use ($db, &$answers): void {
            foreach (['1.1.1.1', '81.2.69.160', '8.8.8.8', '100.64.1.1'] as $ip) {
                Coroutine::create(static function () use ($db, $ip, &$answers): void {
                    $answers[$ip] = $db->lookup($ip)['countryCode'] ?? null;
                });
            }
        });
        ksort($answers);

        expect($answers)->toBe(['1.1.1.1' => 'CH', '100.64.1.1' => 'DE', '8.8.8.8' => 'CH', '81.2.69.160' => 'DE']);
    });
});
