<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Utility methods for IP address lookups.
 *
 * Provides Netbox integration for RFC1918 addresses, geolocation for public
 * ones, and a helper to detect private/reserved IP ranges. All HTTP calls use
 * file_get_contents() which is coroutine-hooked by OpenSwoole SWOOLE_HOOK_ALL
 * and therefore non-blocking.
 */
final class IpLookup {
    /**
     * Geolocation endpoint used when NFSEN_IPINFO_URL is unset. `{ip}` is
     * replaced with the (URL-encoded) address being looked up.
     */
    public const DEFAULT_GEO_URL = 'https://ipapi.co/{ip}/json/';

    /**
     * Returns true if the given IP is a private or reserved address
     * (RFC1918, loopback, link-local, etc.).
     */
    public static function isPrivate(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * The regional-indicator emoji flag for a two-letter ISO 3166-1 country code.
     *
     * A flag emoji is the country's two letters shifted into the regional-indicator
     * block (U+1F1E6..U+1F1FF), which is 'A' plus 127397. Returns '' for anything
     * that isn't a plain two-letter code, so callers render no flag rather than a
     * placeholder implying an unknown country.
     */
    public static function countryFlag(string $countryCode): string {
        $code = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return '';
        }

        return mb_chr(\ord($code[0]) + 127397) . mb_chr(\ord($code[1]) + 127397);
    }

    /**
     * Build the geolocation request URL for an IP from the configured template.
     *
     * `{ip}` is substituted with the URL-encoded address; a template without the
     * placeholder gets the address appended, so a bare base URL such as
     * `https://ipinfo.io/` also works. `{token}` is substituted with
     * NFSEN_IPINFO_TOKEN, which keeps the credential out of the URL — every
     * provider spells its key parameter differently (`?key=`, `?token=`,
     * `?apiKey=`), so the template owns the spelling and the secret stays in its
     * own masked variable. Kept separate from {@see geo()} so the substitution
     * is testable without a network call.
     */
    public static function geoUrl(string $ip): string {
        $template = (string) EnvRegistry::value('NFSEN_IPINFO_URL');
        if ($template === '') {
            $template = self::DEFAULT_GEO_URL;
        }

        $encoded = rawurlencode($ip);
        $url = str_contains($template, '{ip}')
            ? str_replace('{ip}', $encoded, $template)
            : $template . $encoded;

        return str_replace('{token}', rawurlencode((string) EnvRegistry::value('NFSEN_IPINFO_TOKEN')), $url);
    }

    /**
     * Look up geolocation data for a public IP address.
     *
     * The endpoint is configurable via NFSEN_IPINFO_URL because the default
     * (ipapi.co) rate-limits anonymous callers — see issue #163. Error bodies
     * are read rather than discarded (`ignore_errors`), so a rate-limit reply
     * reaches the modal as a message instead of an empty table.
     *
     * @return array<string, mixed> the provider's payload, normalized; empty if the request failed outright
     */
    public static function geo(string $ip): array {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'nfsen-ng',
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        // file_get_contents() populates $http_response_header in this scope; seed it
        // so a non-HTTP wrapper (a misconfigured URL) leaves a defined value behind.
        $http_response_header = [];
        $json = @file_get_contents(self::geoUrl($ip), false, $ctx);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        if (!\is_array($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return self::normalizeGeo($data, $http_response_header);
    }

    /**
     * Query Netbox for an IP address.
     *
     * Calls GET /api/ipam/ip-addresses/?address={ip} on the configured Netbox instance.
     * Returns the first matching IP address object, or null if:
     *  - Netbox is not configured (NFSEN_NETBOX_URL / NFSEN_NETBOX_TOKEN are empty)
     *  - The IP is not found in Netbox
     *  - The request fails or times out
     *
     * @return null|array<string, mixed>
     */
    public static function netbox(string $ip): ?array {
        $url = Config::$settings->netboxUrl;
        $token = Config::$settings->netboxToken;

        if ($url === '' || $token === '') {
            return null;
        }

        $apiUrl = rtrim($url, '/') . '/api/ipam/ip-addresses/?address=' . rawurlencode($ip);
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'nfsen-ng',
                'header' => 'Authorization: Token ' . $token . "\r\nAccept: application/json\r\n",
            ],
        ]);

        $json = @file_get_contents($apiUrl, false, $ctx);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!\is_array($data) || empty($data['results']) || !\is_array($data['results'][0])) {
            return null;
        }

        return $data['results'][0];
    }

    /**
     * Smooth over the differences between geolocation providers so the modal
     * template doesn't have to know which one answered.
     *
     * - `country_flag` is the rendered emoji, so the modal needs no third-party
     *   flag image and works on an install with no outbound internet access.
     * - `country_name` is the flag's tooltip and accessible name. Only ipapi.co
     *   returns it under that key; elsewhere the full name arrives as `country`
     *   (ip-api.com, ipwho.is) or `countryName` (freeipapi.com).
     * - `country_code` is what the flag needs; providers return the
     *   two-letter code as `country` (ipinfo.io) or `countryCode` (ip-api.com)
     *   instead — note `country` is the full name at the latter, hence the
     *   two-letter shape check.
     * - The error branch expects `error` truthy plus a human `reason`. ipinfo.io
     *   nests `{"error": {"title": …, "message": …}}`; ipwho.is (`success: false`)
     *   and ip-api.com (`status: "fail"`) report failure in a 200 response body;
     *   others signal it with the HTTP status alone.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $responseHeaders
     *
     * @return array<string, mixed>
     */
    private static function normalizeGeo(array $data, array $responseHeaders): array {
        if (!isset($data['country_code'])) {
            foreach (['country', 'countryCode'] as $key) {
                $candidate = $data[$key] ?? null;
                if (\is_string($candidate) && preg_match('/^[A-Za-z]{2}$/', $candidate) === 1) {
                    $data['country_code'] = $candidate;

                    break;
                }
            }
        }

        if (isset($data['country_code']) && \is_string($data['country_code'])) {
            $data['country_flag'] = self::countryFlag($data['country_code']);
        }

        if (!isset($data['country_name'])) {
            foreach (['country', 'countryName'] as $key) {
                $candidate = $data[$key] ?? null;
                if (\is_string($candidate) && preg_match('/^[A-Za-z]{2}$/', $candidate) !== 1 && trim($candidate) !== '') {
                    $data['country_name'] = $candidate;

                    break;
                }
            }
        }

        if (isset($data['error']) && \is_array($data['error'])) {
            $nested = $data['error'];
            $parts = array_filter(
                [$nested['title'] ?? null, $nested['message'] ?? null],
                static fn ($v): bool => \is_string($v) && $v !== '',
            );
            $data['reason'] ??= $parts === [] ? 'IP lookup failed' : implode(' — ', $parts);
            $data['error'] = true;
        }

        if (empty($data['error'])
            && (($data['success'] ?? null) === false || ($data['status'] ?? null) === 'fail')) {
            $message = $data['message'] ?? null;
            $data['error'] = true;
            $data['reason'] ??= \is_string($message) && $message !== '' ? $message : 'IP lookup failed';
        }

        $status = self::httpStatus($responseHeaders);
        if ($status !== null && $status >= 400 && empty($data['error'])) {
            $data['error'] = true;
            $data['reason'] ??= 'IP lookup failed (HTTP ' . $status . ')';
        }

        return $data;
    }

    /**
     * First status code out of the $http_response_header lines file_get_contents()
     * leaves behind, or null if they don't look like an HTTP response.
     *
     * @param list<string> $responseHeaders
     */
    private static function httpStatus(array $responseHeaders): ?int {
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }
}
