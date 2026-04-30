<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Utility methods for IP address lookups.
 *
 * Provides Netbox integration for RFC1918 addresses and a helper to detect
 * private/reserved IP ranges. All HTTP calls use file_get_contents() which is
 * coroutine-hooked by OpenSwoole SWOOLE_HOOK_ALL and therefore non-blocking.
 */
final class IpLookup {
    /**
     * Returns true if the given IP is a private or reserved address
     * (RFC1918, loopback, link-local, etc.).
     */
    public static function isPrivate(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
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
}
