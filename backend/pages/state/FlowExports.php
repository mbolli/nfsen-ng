<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

/**
 * Export files of the Flows list, held as compressed pieces until the browser pulls them. Outside
 * the payload budget, bounded instead by KEEP exports per worker and TTL seconds each.
 */
final class FlowExports {
    public const int KEEP = 4;
    public const int TTL = 600;

    /** @var array<string, array{created: int, pieces: list<string>}> by token, oldest first */
    private static array $exports = [];

    /**
     * Keeps $pieces (uncompressed text) under a new token; the oldest export goes past KEEP.
     *
     * @param list<string> $pieces
     */
    public static function add(array $pieces, ?int $now = null): string {
        $now ??= time();
        self::prune($now);
        while (\count(self::$exports) >= self::KEEP) {
            unset(self::$exports[array_key_first(self::$exports)]);
        }
        $token = bin2hex(random_bytes(8));
        self::$exports[$token] = [
            'created' => $now,
            'pieces' => array_map(static fn (string $piece): string => self::compress($piece), $pieces),
        ];

        return $token;
    }

    /** Piece $index of export $token, or null when either is unknown or the export expired. */
    public static function piece(string $token, int $index, ?int $now = null): ?string {
        self::prune($now ?? time());
        $gz = self::$exports[$token]['pieces'][$index] ?? null;
        if ($gz === null) {
            return null;
        }
        $text = $gz === '' ? '' : gzuncompress($gz);

        return \is_string($text) ? $text : null;
    }

    public static function has(string $token): bool {
        return isset(self::$exports[$token]);
    }

    /** For tests: forgets every export. */
    public static function reset(): void {
        self::$exports = [];
    }

    private static function prune(int $now): void {
        foreach (self::$exports as $token => $export) {
            if ($now - $export['created'] >= self::TTL) {
                unset(self::$exports[$token]);
            }
        }
    }

    private static function compress(string $text): string {
        $gz = $text === '' ? '' : gzcompress($text, 6);

        return $gz === false ? '' : $gz;
    }
}
