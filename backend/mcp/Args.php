<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp;

use mbolli\nfsen_ng\common\Config;

/**
 * Normalises tool arguments the SDK has already bound by name.
 *
 * The SDK casts scalars against the parameter's type, but a list argument still arrives as
 * whatever JSON held: nulls, mixed element types, or an object. These turn that into the
 * list<string> and list<int> the query layer declares, rather than letting it through.
 */
final class Args {
    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => \is_scalar($v) ? trim((string) $v) : '', $value),
            static fn (string $v): bool => $v !== ''
        ));
    }

    /**
     * @return list<int>
     */
    public static function intList(mixed $value): array {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => (int) $v,
            array_filter($value, static fn (mixed $v): bool => is_numeric($v))
        ));
    }

    /**
     * Profiles are an explicit argument rather than ambient state, so two concurrent calls
     * cannot read each other's selection.
     */
    public static function profile(string $profile): string {
        return $profile !== '' ? $profile : Config::$settings->nfdumpProfile;
    }
}
