<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\GeoIpDatabase;
use mbolli\nfsen_ng\common\IpLookup;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\mcp\Tool\LookupAddressTool;

/**
 * Stands in for the geolocation web service: registered as the geotest:// wrapper, it serves
 * $body and keeps every requested URL in $requests. Anonymous, so the fixer leaves its name alone.
 */
function ipLookupTestWebService(): object {
    return new class {
        /** @var list<string> */
        public static array $requests = [];

        public static string $body = '{}';

        /** @var null|resource */
        public $context;

        private int $position = 0;

        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
            self::$requests[] = $path;
            $this->position = 0;

            return true;
        }

        public function stream_read(int $count): string {
            $chunk = substr(self::$body, $this->position, $count);
            $this->position += strlen($chunk);

            return $chunk;
        }

        public function stream_eof(): bool {
            return $this->position >= strlen(self::$body);
        }

        /** @return array<string, int> */
        public function stream_stat(): array {
            return ['size' => strlen(self::$body)];
        }
    };
}

/** ipapi.co's answer for a London address (a subset of its fields). */
const IP_LOOKUP_TEST_IPAPI = [
    'ip' => '81.2.69.160',
    'network' => '81.2.64.0/19',
    'version' => 'IPv4',
    'city' => 'London',
    'region' => 'England',
    'region_code' => 'ENG',
    'country' => 'GB',
    'country_name' => 'United Kingdom',
    'country_code' => 'GB',
    'country_code_iso3' => 'GBR',
    'continent_code' => 'EU',
    'in_eu' => false,
    'postal' => 'EC2V',
    'latitude' => 51.5142,
    'longitude' => -0.0931,
    'timezone' => 'Europe/London',
    'asn' => 'AS20712',
    'org' => 'Andrews & Arnold Ltd',
];

/** A GeoIP2 Enterprise record for the same address. */
const IP_LOOKUP_TEST_RECORD = [
    'city' => ['geoname_id' => 2643743, 'names' => ['en' => 'London']],
    'continent' => ['code' => 'EU', 'names' => ['en' => 'Europe']],
    'country' => ['iso_code' => 'GB', 'names' => ['en' => 'United Kingdom']],
    'location' => ['accuracy_radius' => 10, 'latitude' => 51.5142, 'longitude' => -0.0931, 'time_zone' => 'Europe/London'],
    'postal' => ['code' => 'EC2V'],
    'subdivisions' => [['iso_code' => 'ENG', 'names' => ['en' => 'England']]],
    'traits' => ['autonomous_system_number' => 20712, 'autonomous_system_organization' => 'Andrews & Arnold Ltd', 'isp' => 'Andrews & Arnold'],
];

/**
 * A GeoIpDatabase over a fake reader that answers $record for every address and counts the calls.
 *
 * @param mixed $record returned as the reader's record, or thrown when it is a Throwable
 */
function ipLookupTestDatabase(mixed $record, int &$calls = 0): GeoIpDatabase {
    return new GeoIpDatabase('/fake/GeoIP2-Enterprise.mmdb', static function (string $ip) use ($record, &$calls): mixed {
        ++$calls;
        if ($record instanceof Throwable) {
            throw $record;
        }

        return $record;
    }, ['type' => 'GeoIP2-Enterprise', 'buildEpoch' => 1758585600, 'ipVersion' => 6]);
}

beforeEach(function (): void {
    putenv('NFSEN_IPINFO_URL');
    putenv('NFSEN_IPINFO_TOKEN');
    // The web path unless a test injects a database.
    GeoIpDatabase::useShared(null);
});

afterEach(function (): void {
    GeoIpDatabase::resetShared();
});

/**
 * IpLookup::normalizeGeo() is private, an implementation detail of geo(). Reaching it
 * directly covers every provider shape (the point of #163) without a service per shape.
 *
 * @param array<string, mixed> $data
 * @param list<string>         $headers
 *
 * @return array<string, mixed>
 */
function normalizeGeo(array $data, array $headers = []): array {
    $m = new ReflectionMethod(IpLookup::class, 'normalizeGeo');

    /** @var array<string, mixed> */
    return $m->invoke(null, $data, $headers);
}

describe('IpLookup::geoUrl()', function (): void {
    test('defaults to ipapi.co with the address substituted', function (): void {
        expect(IpLookup::geoUrl('1.1.1.1'))->toBe('https://ipapi.co/1.1.1.1/json/');
    });

    test('substitutes {ip} anywhere in a configured template, including a query', function (): void {
        putenv('NFSEN_IPINFO_URL=https://ipinfo.io/{ip}/json?token=abc123');

        expect(IpLookup::geoUrl('8.8.8.8'))->toBe('https://ipinfo.io/8.8.8.8/json?token=abc123');
    });

    test('appends the address when the template has no placeholder', function (): void {
        putenv('NFSEN_IPINFO_URL=https://ipinfo.io/');

        expect(IpLookup::geoUrl('8.8.8.8'))->toBe('https://ipinfo.io/8.8.8.8');
    });

    test('url-encodes the address', function (): void {
        expect(IpLookup::geoUrl('2001:db8::1'))->toBe('https://ipapi.co/2001%3Adb8%3A%3A1/json/');
    });

    test('substitutes {token} with the configured key', function (): void {
        putenv('NFSEN_IPINFO_URL=https://ipapi.co/{ip}/json/?key={token}');
        putenv('NFSEN_IPINFO_TOKEN=s3cr3t');

        expect(IpLookup::geoUrl('1.1.1.1'))->toBe('https://ipapi.co/1.1.1.1/json/?key=s3cr3t');
    });

    test('url-encodes the token', function (): void {
        putenv('NFSEN_IPINFO_URL=https://example.test/{ip}?key={token}');
        putenv('NFSEN_IPINFO_TOKEN=a b&c');

        expect(IpLookup::geoUrl('1.1.1.1'))->toBe('https://example.test/1.1.1.1?key=a%20b%26c');
    });

    test('leaves the URL alone when no token is configured', function (): void {
        putenv('NFSEN_IPINFO_TOKEN=s3cr3t');

        expect(IpLookup::geoUrl('1.1.1.1'))->toBe('https://ipapi.co/1.1.1.1/json/');
    });

    test('falls back to the default when the variable is set empty', function (): void {
        putenv('NFSEN_IPINFO_URL=');

        expect(IpLookup::geoUrl('1.1.1.1'))->toBe('https://ipapi.co/1.1.1.1/json/');
    });
});

describe('IpLookup::countryFlag()', function (): void {
    test('maps a two-letter code to its regional-indicator pair', function (): void {
        expect(IpLookup::countryFlag('AU'))->toBe('🇦🇺')
            ->and(IpLookup::countryFlag('CH'))->toBe('🇨🇭')
            ->and(IpLookup::countryFlag('US'))->toBe('🇺🇸')
        ;
    });

    test('accepts lowercase and surrounding whitespace', function (): void {
        expect(IpLookup::countryFlag('us'))->toBe(IpLookup::countryFlag('US'))
            ->and(IpLookup::countryFlag(' ch '))->toBe(IpLookup::countryFlag('CH'))
        ;
    });

    test('returns an empty string for anything that is not a two-letter code', function (): void {
        expect(IpLookup::countryFlag('Australia'))->toBe('')
            ->and(IpLookup::countryFlag(''))->toBe('')
            ->and(IpLookup::countryFlag('X'))->toBe('')
            ->and(IpLookup::countryFlag('U1'))->toBe('')
        ;
    });
});

describe('IpLookup geo response normalization', function (): void {
    test('passes an ipapi.co payload through unchanged apart from the added flag', function (): void {
        $payload = ['ip' => '1.1.1.1', 'country' => 'AU', 'country_code' => 'AU', 'city' => 'Sydney'];

        expect(normalizeGeo($payload))->toBe($payload + ['country_flag' => '🇦🇺']);
    });

    test('derives country_code from a two-letter country (ipinfo.io shape)', function (): void {
        $out = normalizeGeo(['ip' => '8.8.8.8', 'country' => 'US', 'loc' => '37.4,-122.0']);

        expect($out['country_code'])->toBe('US');
    });

    test('adds the flag emoji for every provider shape that yields a code', function (): void {
        expect(normalizeGeo(['country_code' => 'AU'])['country_flag'])->toBe('🇦🇺')
            ->and(normalizeGeo(['country' => 'US'])['country_flag'])->toBe('🇺🇸')
            ->and(normalizeGeo(['country' => 'Australia', 'countryCode' => 'AU'])['country_flag'])->toBe('🇦🇺')
        ;
    });

    test('derives the country name from whichever key the provider used', function (): void {
        expect(normalizeGeo(['country' => 'Australia', 'countryCode' => 'AU'])['country_name'])->toBe('Australia')
            ->and(normalizeGeo(['countryName' => 'Switzerland'])['country_name'])->toBe('Switzerland')
            ->and(normalizeGeo(['country_name' => 'Australia', 'country' => 'AU'])['country_name'])->toBe('Australia')
        ;
    });

    test('does not mistake a two-letter code for a country name', function (): void {
        expect(normalizeGeo(['country' => 'US']))->not->toHaveKey('country_name');
    });

    test('omits the flag when no country code could be derived', function (): void {
        expect(normalizeGeo(['country' => 'United States']))->not->toHaveKey('country_flag');
    });

    test('derives country_code from countryCode when country is a full name (ip-api.com shape)', function (): void {
        $out = normalizeGeo(['country' => 'Australia', 'countryCode' => 'AU']);

        expect($out['country_code'])->toBe('AU');
    });

    test('does not mistake a country name for a code', function (): void {
        $out = normalizeGeo(['country' => 'United States']);

        expect($out)->not->toHaveKey('country_code');
    });

    test('flattens a nested error object into error/reason', function (): void {
        $out = normalizeGeo(['error' => ['title' => 'Rate limit', 'message' => 'try later']]);

        expect($out['error'])->toBeTrue()
            ->and($out['reason'])->toBe('Rate limit: try later')
        ;
    });

    test('keeps a flat ipapi.co rate-limit reply as-is', function (): void {
        $out = normalizeGeo(['error' => true, 'reason' => 'RateLimited']);

        expect($out['error'])->toBeTrue()
            ->and($out['reason'])->toBe('RateLimited')
        ;
    });

    test('flags a 200-status failure body (ipwho.is success:false)', function (): void {
        $out = normalizeGeo(['ip' => '999.999.999.999', 'success' => false, 'message' => 'Invalid IP address']);

        expect($out['error'])->toBeTrue()
            ->and($out['reason'])->toBe('Invalid IP address')
        ;
    });

    test('flags a 200-status failure body (ip-api.com status:fail)', function (): void {
        $out = normalizeGeo(['status' => 'fail', 'message' => 'invalid query']);

        expect($out['error'])->toBeTrue()
            ->and($out['reason'])->toBe('invalid query')
        ;
    });

    test('leaves a successful ipwho.is/ip-api payload alone', function (): void {
        $out = normalizeGeo(['success' => true, 'status' => 'success', 'country_code' => 'AU']);

        expect($out)->not->toHaveKey('error');
    });

    test('reports an HTTP error status when the body carries no error of its own', function (): void {
        $out = normalizeGeo(['ip' => '1.1.1.1'], ['HTTP/1.1 429 Too Many Requests']);

        expect($out['error'])->toBeTrue()
            ->and($out['reason'])->toBe('IP lookup failed (HTTP 429)')
        ;
    });

    test('ignores a successful status line', function (): void {
        $out = normalizeGeo(['ip' => '1.1.1.1'], ['HTTP/1.1 200 OK', 'Content-Type: application/json']);

        expect($out)->not->toHaveKey('error');
    });
});

describe('IpLookup::geo() failure reporting', function (): void {
    test('an unreachable service reports a reason rather than an empty result', function (): void {
        // Port 1 on loopback refuses immediately, so this stays offline and fast.
        putenv('NFSEN_IPINFO_URL=http://127.0.0.1:1/{ip}');

        // geo() suppresses the connection warning with @, but Pest's error handler still
        // reports it; silence it here so a deliberate failure isn't flagged as a warning.
        set_error_handler(static fn (): bool => true);
        $result = IpLookup::geo('1.1.1.1');
        restore_error_handler();

        // Empty would be indistinguishable from "no lookup attempted" (a private IP), which
        // the modal renders as nothing at all, so the failure has to be visible instead (#168).
        expect($result)->not->toBeEmpty()
            ->and($result['error'])->toBeTrue()
            ->and($result['reason'])->toBeString()->not->toBeEmpty()
        ;
    });
});

describe('IpLookup::geo() and the local GeoIP database', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        Config::$settings = Settings::fromArray(mockSettings())->withGeoipDb('');
        $this->web = ipLookupTestWebService();
        $this->web::$requests = [];
        $this->web::$body = json_encode(IP_LOOKUP_TEST_IPAPI, JSON_THROW_ON_ERROR);
        stream_wrapper_register('geotest', $this->web::class);
        putenv('NFSEN_IPINFO_URL=geotest://{ip}');
    });

    afterEach(function (): void {
        stream_wrapper_unregister('geotest');
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
    });

    test('with NFSEN_GEOIP_DB unset it asks the web service as before', function (): void {
        GeoIpDatabase::resetShared();

        $out = IpLookup::geo('81.2.69.160');

        expect($this->web::$requests)->toBe(['geotest://81.2.69.160'])
            ->and($out)->toBe(IP_LOOKUP_TEST_IPAPI + ['country_flag' => IpLookup::countryFlag('GB')])
        ;
    });

    test('with a database file that cannot be opened it still asks the web service', function (): void {
        GeoIpDatabase::resetShared();
        Config::$settings = Config::$settings->withGeoipDb(sys_get_temp_dir() . '/nfsen-missing-' . bin2hex(random_bytes(4)) . '.mmdb');

        $out = IpLookup::geo('81.2.69.160');

        expect($this->web::$requests)->toHaveCount(1)
            ->and($out)->not->toHaveKey('source')
        ;
    });

    test('answers from the database without a request', function (): void {
        $calls = 0;
        GeoIpDatabase::useShared(ipLookupTestDatabase(IP_LOOKUP_TEST_RECORD, $calls));

        $out = IpLookup::geo('81.2.69.160');

        expect($this->web::$requests)->toBe([])
            ->and($calls)->toBe(1)
            ->and($out)->toBe([
                'ip' => '81.2.69.160',
                'city' => 'London',
                'region' => 'England',
                'country_name' => 'United Kingdom',
                'country_code' => 'GB',
                'latitude' => 51.5142,
                'longitude' => -0.0931,
                'asn' => 'AS20712',
                'org' => 'Andrews & Arnold Ltd',
                'country_flag' => IpLookup::countryFlag('GB'),
                'source' => IpLookup::SOURCE_MAXMIND,
            ])
        ;
    });

    test('names every field the way the normalised web answer does', function (): void {
        $web = IpLookup::geo('81.2.69.160');
        GeoIpDatabase::useShared(ipLookupTestDatabase(IP_LOOKUP_TEST_RECORD));
        $local = IpLookup::geo('81.2.69.160');
        unset($local['source']);

        $shared = array_intersect_key($web, $local);
        ksort($shared);
        ksort($local);

        expect($local)->toBe($shared)
            ->and(array_keys($local))->toContain('country_code', 'country_name', 'country_flag')
        ;
    });

    test('leaves out what a Country database does not know', function (): void {
        GeoIpDatabase::useShared(ipLookupTestDatabase(['country' => ['iso_code' => 'DE', 'names' => ['en' => 'Germany']]]));

        expect(IpLookup::geo('5.9.0.1'))->toBe([
            'ip' => '5.9.0.1',
            'country_name' => 'Germany',
            'country_code' => 'DE',
            'country_flag' => IpLookup::countryFlag('DE'),
            'source' => IpLookup::SOURCE_MAXMIND,
        ]);
    });

    test('says so when the database has no entry, still without a request', function (): void {
        GeoIpDatabase::useShared(ipLookupTestDatabase(null));

        $out = IpLookup::geo('81.2.69.160');

        expect($this->web::$requests)->toBe([])
            ->and($out['error'])->toBeTrue()
            ->and($out['reason'])->toBe('The local GeoIP database has no entry for this address.')
            ->and($out['source'])->toBe(IpLookup::SOURCE_MAXMIND)
        ;
    });

    test('reports a database read failure instead of asking the web service', function (): void {
        GeoIpDatabase::useShared(ipLookupTestDatabase(new RuntimeException('The MaxMind DB file is corrupt')));

        $out = IpLookup::geo('81.2.69.160');

        expect($this->web::$requests)->toBe([])
            ->and($out['error'])->toBeTrue()
            ->and($out['reason'])->toBe('The local GeoIP database could not be read: The MaxMind DB file is corrupt')
        ;
    });

    test('never looks up a private address, locally or on the web', function (): void {
        $calls = 0;
        GeoIpDatabase::useShared(ipLookupTestDatabase(IP_LOOKUP_TEST_RECORD, $calls));

        $out = (new LookupAddressTool())('192.168.1.10');

        expect($out['geo'])->toBeNull()
            ->and($calls)->toBe(0)
            ->and($this->web::$requests)->toBe([])
        ;
    });
});
