<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\IpLookup;

beforeEach(function (): void {
    putenv('NFSEN_IPINFO_URL');
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

    test('falls back to the default when the variable is set empty', function (): void {
        putenv('NFSEN_IPINFO_URL=');

        expect(IpLookup::geoUrl('1.1.1.1'))->toBe('https://ipapi.co/1.1.1.1/json/');
    });
});

describe('IpLookup geo response normalization', function (): void {
    test('leaves an ipapi.co payload untouched', function (): void {
        $payload = ['ip' => '1.1.1.1', 'country' => 'AU', 'country_code' => 'AU', 'city' => 'Sydney'];

        expect(normalizeGeo($payload))->toBe($payload);
    });

    test('derives country_code from a two-letter country (ipinfo.io shape)', function (): void {
        $out = normalizeGeo(['ip' => '8.8.8.8', 'country' => 'US', 'loc' => '37.4,-122.0']);

        expect($out['country_code'])->toBe('US');
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
