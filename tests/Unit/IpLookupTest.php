<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\IpLookup;

beforeEach(function (): void {
    putenv('NFSEN_IPINFO_URL');
    putenv('NFSEN_IPINFO_TOKEN');
});

/**
 * IpLookup::normalizeGeo() is private — it's an implementation detail of geo(),
 * which can't be called without a network round-trip. Reach it directly rather
 * than losing coverage of the provider-compat logic that is the point of #163.
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
            ->and($out['reason'])->toBe('Rate limit — try later')
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
        // the modal renders as nothing at all — the failure has to be visible instead (#168).
        expect($result)->not->toBeEmpty()
            ->and($result['error'])->toBeTrue()
            ->and($result['reason'])->toBeString()->not->toBeEmpty()
        ;
    });
});
