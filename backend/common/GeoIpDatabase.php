<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use MaxMind\Db\Reader;
use OpenSwoole\Coroutine;

/**
 * Local IP geolocation from the MaxMind database NFSEN_GEOIP_DB points at (GeoLite2 or GeoIP2
 * City, Country, ASN or ISP, or a DB-IP file in the same format), so a lookup needs no network.
 *
 * The reader comes in as a record function plus its metadata, so tests can fake it without a file.
 *
 * @phpstan-type GeoRecord array{country: string, countryCode: string, region: string, city: string, latitude: ?float, longitude: ?float, asn: string, org: string}
 * @phpstan-type GeoMetadata array{type: string, buildEpoch: int, ipVersion: int}
 * @phpstan-type GeoStatus array{path: string, readable: bool, type: string, buildEpoch: int, ipVersion: int, error: string}
 */
final class GeoIpDatabase {
    private static ?self $shared = null;

    /** The file state (path, mtime, ctime, size, inode) that $shared or $sharedError belongs to. */
    private static string $sharedKey = '';

    private static string $sharedError = '';

    private static bool $pinned = false;

    private bool $busy = false;

    /**
     * @param \Closure(string): mixed $record   the database record for an address, null when it has none
     * @param GeoMetadata             $metadata
     */
    public function __construct(
        public readonly string $path,
        private readonly \Closure $record,
        public readonly array $metadata,
    ) {}

    /**
     * Opened once per process, reopened when the file's mtime changes. Null when NFSEN_GEOIP_DB
     * is empty or the file cannot be opened as a MaxMind database ({@see status()} says why).
     */
    public static function shared(): ?self {
        if (self::$pinned) {
            return self::$shared;
        }

        $path = self::configuredPath();
        if ($path === '') {
            self::$shared = null;
            self::$sharedKey = '';
            self::$sharedError = '';

            return null;
        }

        // A failed open is cached with the file state too: scanning a large non-mmdb file for
        // the metadata marker is slow, so it is retried only once the file changes.
        $key = self::fileKey($path);
        if ($key !== self::$sharedKey) {
            try {
                $db = self::open($path);
                $error = '';
            } catch (\RuntimeException $e) {
                $db = null;
                $error = $e->getMessage();
            }
            self::$shared = $db;
            self::$sharedError = $error;
            self::$sharedKey = $key;
        }

        return self::$shared;
    }

    /**
     * @throws \RuntimeException when the file is missing, unreadable or not a MaxMind database
     */
    public static function open(string $path): self {
        clearstatcache(true, $path);
        if (is_dir($path)) {
            throw new \RuntimeException('The path is a directory, not an .mmdb file.');
        }
        // is_file() and opening the file check the effective uid; file_exists() and is_readable() ask
        // access(), which checks the real one. SplFileObject throws instead of raising a warning.
        if (!is_file($path)) {
            $blocked = self::blockedDirectory($path);

            throw new \RuntimeException($blocked === ''
                ? 'File not found.'
                : \sprintf('The directory %s is not accessible to the nfsen-ng process (check its permissions).', $blocked));
        }

        try {
            new \SplFileObject($path, 'rb');
        } catch (\RuntimeException) {
            throw new \RuntimeException('The file is not readable by the nfsen-ng process (check its permissions).');
        }

        try {
            $reader = new Reader($path);
            $meta = $reader->metadata();
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        return new self($path, $reader->get(...), [
            'type' => (string) $meta->databaseType,
            'buildEpoch' => (int) $meta->buildEpoch,
            'ipVersion' => (int) $meta->ipVersion,
        ]);
    }

    /** Pins the instance shared() returns (tests); null makes IP lookups use the web service. */
    public static function useShared(?self $db): void {
        self::$pinned = true;
        self::$shared = $db;
        self::$sharedKey = '';
        self::$sharedError = '';
    }

    public static function resetShared(): void {
        self::$pinned = false;
        self::$shared = null;
        self::$sharedKey = '';
        self::$sharedError = '';
    }

    /**
     * Null for an address the database has no usable entry for, including an IPv6 address in an
     * IPv4-only database.
     *
     * @return null|GeoRecord IpLookup::geo() renames the keys to the ones the web answers normalise to
     *
     * @throws \Throwable when the reader fails, e.g. on a corrupt file
     */
    public function lookup(string $ip): ?array {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if ($this->metadata['ipVersion'] === 4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return null;
        }

        // The pure-PHP reader seeks one file handle and yields on every read under the file hook,
        // so a second coroutine waits here instead of moving it mid-lookup.
        while ($this->busy && Coroutine::getCid() > 0) {
            Coroutine::usleep(1000);
        }
        $this->busy = true;

        try {
            $record = ($this->record)($ip);
        } finally {
            $this->busy = false;
        }

        return \is_array($record) ? self::fromRecord($record) : null;
    }

    /**
     * @return GeoStatus
     */
    public static function status(): array {
        $db = self::shared();
        if ($db !== null) {
            return [
                'path' => $db->path,
                'readable' => true,
                'type' => $db->metadata['type'],
                'buildEpoch' => $db->metadata['buildEpoch'],
                'ipVersion' => $db->metadata['ipVersion'],
                'error' => '',
            ];
        }

        return [
            'path' => self::$pinned ? '' : self::configuredPath(),
            'readable' => false,
            'type' => '',
            'buildEpoch' => 0,
            'ipVersion' => 0,
            'error' => self::$sharedError,
        ];
    }

    private static function configuredPath(): string {
        return trim(isset(Config::$settings) ? Config::$settings->geoipDb : (string) EnvRegistry::value('NFSEN_GEOIP_DB'));
    }

    private static function fileKey(string $path): string {
        clearstatcache(true, $path);
        $stat = is_file($path) ? stat($path) : false;
        if ($stat === false) {
            // Keyed by the blocking directory too, so status() follows a permission change.
            return $path . '|missing|' . self::blockedDirectory($path);
        }

        return implode('|', [$path, $stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino']]);
    }

    /**
     * The deepest existing directory above $path that the process may not enter, '' when every
     * existing one is accessible (then the file is simply missing).
     */
    private static function blockedDirectory(string $path): string {
        $dir = \dirname($path);
        while (!is_dir($dir)) {
            if (\dirname($dir) === $dir) {
                return '';
            }
            $dir = \dirname($dir);
        }

        return is_dir($dir . '/.') ? '' : $dir;
    }

    /**
     * Reads the GeoIP2 layout (City and Country nest names by locale; ASN and ISP are flat,
     * Enterprise keeps the AS under traits).
     *
     * @param array<mixed> $record
     *
     * @return null|GeoRecord
     */
    private static function fromRecord(array $record): ?array {
        $country = self::node($record, 'country');
        if (self::name($country) === '' && self::text($country, 'iso_code') === '') {
            $country = self::node($record, 'registered_country');
        }

        $location = self::node($record, 'location');
        $traits = self::node($record, 'traits');
        $asNumber = $record['autonomous_system_number'] ?? $traits['autonomous_system_number'] ?? null;

        $row = [
            'country' => self::name($country),
            'countryCode' => strtoupper(self::text($country, 'iso_code')),
            'region' => self::name(self::node(self::node($record, 'subdivisions'), 0)),
            'city' => self::name(self::node($record, 'city')),
            'latitude' => self::coordinate($location, 'latitude'),
            'longitude' => self::coordinate($location, 'longitude'),
            'asn' => \is_int($asNumber) && $asNumber > 0 ? 'AS' . $asNumber : '',
            'org' => self::firstText([$record, $traits], ['autonomous_system_organization', 'organization', 'isp']),
        ];

        return array_filter($row, static fn (mixed $v): bool => $v !== '' && $v !== null) === [] ? null : $row;
    }

    /**
     * @param array<mixed> $record
     *
     * @return array<mixed>
     */
    private static function node(array $record, int|string $key): array {
        $node = $record[$key] ?? null;

        return \is_array($node) ? $node : [];
    }

    /**
     * @param list<array<mixed>> $nodes
     * @param list<string>       $keys
     */
    private static function firstText(array $nodes, array $keys): string {
        foreach ($nodes as $node) {
            foreach ($keys as $key) {
                $text = self::text($node, $key);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $node
     */
    private static function text(array $node, string $key): string {
        $value = $node[$key] ?? null;

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * English name of a GeoIP2 place, else the first name the database has.
     *
     * @param array<mixed> $place
     */
    private static function name(array $place): string {
        $names = self::node($place, 'names');
        $english = self::text($names, 'en');
        if ($english !== '') {
            return $english;
        }
        foreach ($names as $name) {
            if (\is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $location
     */
    private static function coordinate(array $location, string $key): ?float {
        $value = $location[$key] ?? null;

        return \is_float($value) || \is_int($value) ? (float) $value : null;
    }
}
