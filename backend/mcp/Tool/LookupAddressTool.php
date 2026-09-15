<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\IpLookup;
use mbolli\nfsen_ng\mcp\Tier;

/**
 * Turns an address into something a human recognises.
 */
final class LookupAddressTool implements ToolInterface {
    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $ip): array {
        $private = IpLookup::isPrivate($ip);

        return [
            'ip' => $ip,
            'private' => $private,
            // Geolocating an address that is not routable on the internet tells you nothing,
            // and would leak the query to a third party for no benefit.
            'geo' => $private ? null : IpLookup::geo($ip),
            'netbox' => IpLookup::netbox($ip),
        ];
    }

    public static function name(): string {
        return 'lookup_address';
    }

    public static function description(): string {
        return 'Context for an IP address: geolocation and network ownership for public '
            . 'addresses, and the Netbox device, interface and tenant for addresses documented '
            . 'there. A private address gets no geolocation lookup, by design.';
    }

    public static function tier(): Tier {
        // No capture files, but it may call an external service, so it is not free either.
        return Tier::Cheap;
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'ip' => ['type' => 'string', 'description' => 'The IPv4 or IPv6 address to look up.'],
            ],
            'required' => ['ip'],
        ];
    }
}
