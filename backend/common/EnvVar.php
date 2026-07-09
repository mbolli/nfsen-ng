<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Declarative description of a single environment variable nfsen-ng understands.
 *
 * One immutable record per variable lives in {@see EnvRegistry}. Everything the
 * app does with env vars — parsing to a typed value, validating, applying the
 * default, deprecation aliasing, config-reference docs, and the health-page
 * "unknown/invalid variable" checks — derives from these records, so there is a
 * single source of truth instead of scattered `getenv() ?: default` calls.
 *
 * `parse()` never throws: an unset, empty, or invalid value falls back to
 * `default`. Whether a *set* value was invalid (so the fallback kicked in) is
 * reported separately by `validationError()`, which the health check surfaces.
 *
 * @phpstan-type EnvType 'string'|'int'|'bool'|'enum'|'csv'|'csv_int'|'json_array'
 * @phpstan-type EnvFormat 'url'|'email'|'path'
 */
final class EnvVar {
    /**
     * @param string            $name    canonical variable name, e.g. NFSEN_IMPORT_YEARS
     * @param string            $group   grouping key for docs/health rendering (core, nfdump, datasource, …)
     * @param EnvType           $type    how the raw string is parsed and hard-validated
     * @param mixed             $default value used when unset/empty/invalid
     * @param string            $doc     one-line human description for generated docs and health hints
     * @param null|list<string> $enum    canonical allowed values (case-insensitive match; canonical form is returned)
     * @param null|int          $min     lower clamp for type=int (also the floor an out-of-range value is pulled up to)
     * @param null|EnvFormat    $format  soft/semantic hint used by the health check (url/email/path); not enforced here
     * @param null|string       $alias   deprecated former name still honoured (e.g. VM_HOST → NFSEN_VM_HOST)
     * @param bool              $secret  mask the value in any surfaced message (tokens, credentials)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $group,
        public readonly string $type,
        public readonly mixed $default,
        public readonly string $doc,
        public readonly ?array $enum = null,
        public readonly ?int $min = null,
        public readonly ?string $format = null,
        public readonly ?string $alias = null,
        public readonly bool $secret = false,
    ) {}

    /**
     * Parse a raw env string (or null when unset) into the typed value.
     * Unset, empty, or invalid input yields {@see $default} — never throws.
     */
    public function parse(?string $raw): mixed {
        if ($raw === null || $raw === '') {
            return $this->default;
        }

        return match ($this->type) {
            'int' => $this->parseInt($raw),
            'bool' => $this->parseBool($raw) ?? $this->default,
            'enum' => $this->matchEnum($raw) ?? $this->default,
            'csv' => self::parseCsv($raw),
            'csv_int' => array_map('intval', self::parseCsv($raw)),
            'json_array' => \is_array($decoded = json_decode($raw, true)) ? $decoded : $this->default,
            default => $raw,
        };
    }

    /**
     * If a *set* value is unusable for this variable's type (so parse() fell
     * back to the default), return a human message explaining it; otherwise
     * null. Unset/empty is always fine. Soft format checks (url/email/path)
     * are intentionally not covered here — the health check owns those.
     */
    public function validationError(?string $raw): ?string {
        if ($raw === null || $raw === '') {
            return null;
        }

        $ok = match ($this->type) {
            'int' => is_numeric($raw),
            'bool' => $this->parseBool($raw) !== null,
            'enum' => $this->matchEnum($raw) !== null,
            'json_array' => \is_array(json_decode($raw, true)),
            default => true,
        };
        if ($ok) {
            return null;
        }

        return \sprintf(
            'invalid value %s (expected %s) — falling back to default %s',
            $this->display($raw),
            $this->expected(),
            $this->display((string) $this->displayDefault()),
        );
    }

    /** Human description of what this variable accepts, for messages and docs. */
    public function expected(): string {
        return match ($this->type) {
            'int' => $this->min !== null ? "integer ≥ {$this->min}" : 'integer',
            'bool' => 'boolean (true/false, 1/0, yes/no, on/off)',
            'enum' => 'one of ' . implode(', ', $this->enum ?? []),
            'csv' => 'comma-separated list',
            'csv_int' => 'comma-separated integers',
            'json_array' => 'JSON array',
            default => 'string',
        };
    }

    /** Mask secrets when echoing a value back to the user. */
    public function display(string $value): string {
        if ($this->secret && $value !== '') {
            return '***';
        }

        return "'{$value}'";
    }

    /** Default rendered for messages (arrays as CSV, everything else stringified). */
    private function displayDefault(): string {
        if (\is_array($this->default)) {
            return implode(',', $this->default);
        }
        if (\is_bool($this->default)) {
            return $this->default ? 'true' : 'false';
        }

        return (string) $this->default;
    }

    private function parseInt(string $raw): int {
        if (!is_numeric($raw)) {
            return \is_int($this->default) ? $this->default : 0;
        }
        $v = (int) $raw;

        return $this->min !== null ? max($this->min, $v) : $v;
    }

    /** true/false for recognised truthy/falsey strings, null for anything else. */
    private function parseBool(string $raw): ?bool {
        return match (strtolower(trim($raw))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /** Case-insensitive enum match; returns the canonical entry or null. */
    private function matchEnum(string $raw): ?string {
        $needle = strtolower(trim($raw));
        foreach ($this->enum ?? [] as $canonical) {
            if (strtolower($canonical) === $needle) {
                return $canonical;
            }
        }

        return null;
    }

    /** @return list<string> trimmed, non-empty items */
    private static function parseCsv(string $raw): array {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }
}
