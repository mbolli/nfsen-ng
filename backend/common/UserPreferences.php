<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * User-editable preferences stored as JSON in preferences.json.
 *
 * These are overlaid on top of deployment-time Settings after boot, allowing
 * users to change UI defaults and filters without touching env vars or settings.php.
 *
 * On save: write atomically (tmp + rename) to avoid corrupt reads.
 * On load: silently return null if the file doesn't exist yet — Config falls back to Settings defaults.
 */
final class UserPreferences {
    /** @param string[] $defaultGraphProtocols  @param string[] $filters */
    public function __construct(
        public readonly string $defaultView,
        public readonly string $defaultGraphDisplay,
        public readonly string $defaultGraphDatatype,
        public readonly array $defaultGraphProtocols,
        public readonly int $defaultFlowLimit,
        public readonly string $defaultStatsOrderBy,
        public readonly array $filters,
        public readonly int $logPriority,
        public readonly string $selectedProfile = 'live',
    ) {}

    /**
     * Load from a JSON file.
     * Returns null when the file doesn't exist or is unreadable — caller uses Settings defaults.
     */
    public static function load(string $path): ?self {
        if (!file_exists($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return \is_array($data) ? self::fromArray($data) : null;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        return new self(
            defaultView: (string) ($data['defaultView'] ?? 'graphs'),
            defaultGraphDisplay: (string) ($data['defaultGraphDisplay'] ?? 'sources'),
            defaultGraphDatatype: (string) ($data['defaultGraphDatatype'] ?? 'traffic'),
            defaultGraphProtocols: (array) ($data['defaultGraphProtocols'] ?? ['any']),
            defaultFlowLimit: (int) ($data['defaultFlowLimit'] ?? 50),
            defaultStatsOrderBy: (string) ($data['defaultStatsOrderBy'] ?? 'bytes'),
            filters: array_values(array_filter(array_map('strval', (array) ($data['filters'] ?? [])))),
            logPriority: (int) ($data['logPriority'] ?? LOG_INFO),
            selectedProfile: (string) ($data['selectedProfile'] ?? 'live'),
        );
    }

    /**
     * Apply these preferences on top of a base Settings instance.
     * Returns a new Settings object with user preferences overlaid.
     */
    public function applyTo(Settings $settings): Settings {
        return $settings
            ->withDefaultView($this->defaultView)
            ->withDefaultGraphDisplay($this->defaultGraphDisplay)
            ->withDefaultGraphDatatype($this->defaultGraphDatatype)
            ->withDefaultGraphProtocols($this->defaultGraphProtocols)
            ->withDefaultFlowLimit($this->defaultFlowLimit)
            ->withDefaultStatsOrderBy($this->defaultStatsOrderBy)
            ->withFilters($this->filters)
            ->withLogPriority($this->logPriority)
        ;
    }

    /**
     * Atomically save preferences to a JSON file.
     *
     * @throws \RuntimeException on write or rename failure
     */
    public function save(string $path): void {
        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new \RuntimeException("Cannot create preferences directory: {$dir}");
        }

        $tmp = $path . '.tmp.' . getmypid();

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write preferences to: {$tmp}");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException("Cannot rename preferences file to: {$path}");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'defaultView' => $this->defaultView,
            'defaultGraphDisplay' => $this->defaultGraphDisplay,
            'defaultGraphDatatype' => $this->defaultGraphDatatype,
            'defaultGraphProtocols' => $this->defaultGraphProtocols,
            'defaultFlowLimit' => $this->defaultFlowLimit,
            'defaultStatsOrderBy' => $this->defaultStatsOrderBy,
            'filters' => $this->filters,
            'logPriority' => $this->logPriority,
            'selectedProfile' => $this->selectedProfile,
        ];
    }

    /** Return a copy with the selectedProfile changed. */
    public function withSelectedProfile(string $profile): self {
        $arr = $this->toArray();
        $arr['selectedProfile'] = $profile;

        return self::fromArray($arr);
    }
}
