<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

/**
 * Per-tab results of the Top Talkers page: the last run of every statistic (D12), newest
 * last, and the two side panels.
 *
 * @phpstan-import-type Notification from PageState
 *
 * @phpstan-type StatsInputs array{count: int, order: string, filter: string, lower: string, upper: string, aggregation: array<string, bool|string>}
 * @phpstan-type StatsResult array{id: string, html: string, command: string, notes: list<string>, warnings: list<Notification>, elapsed: float, rows: int, ts: int, from: int, to: int, live: bool, fingerprint: string, scope: string, inputs: StatsInputs}
 * @phpstan-type StatsResultMeta array{command?: string, notes?: list<string>, warnings?: list<Notification>, elapsed?: float, rows?: int, ts?: int, from?: int, to?: int, live?: bool, fingerprint?: string, scope?: string, inputs?: StatsInputs}
 * @phpstan-type PanelInputs array{filter: string, lower: string, upper: string}
 * @phpstan-type PanelBar array{key: string, label: string, bytes: int, share: ?float, series: ?int}
 * @phpstan-type PanelResult array{bars: list<PanelBar>, command: string, error: string, elapsed: float, ts: int, from: int, to: int, live: bool, fingerprint: string, scope: string, inputs: PanelInputs, clamp: string}
 */
final class TalkersState extends PageState {
    /** Statistics kept per tab; the oldest run goes first. */
    public const int MAX_RESULTS = 8;

    /** Runs a revival snapshot keeps: tables are large and the snapshots are app-global. */
    public const int SNAPSHOT_RESULTS = 3;

    /** @var list<string> the side panels, in page order */
    public const array PANELS = ['proto', 'as'];

    /** @var array<string, StatsResult> element => its last run, oldest first */
    public array $results = [];

    /** @var array<string, PanelResult> panel => its last run */
    public array $panels = [];

    /** The statistic the last run or failure was for; the page notices belong to it ('' = to any). */
    public string $lastElement = '';

    /** Changes with every stored result (D26). */
    public string $resultId {
        get => $this->results[$this->lastElement]['id'] ?? '';
    }

    /**
     * Stores a run of `$element`, replacing its previous one, and forgets the oldest statistic
     * beyond MAX_RESULTS.
     *
     * @param StatsResultMeta $meta
     */
    public function setResult(string $html, string $element = 'record', array $meta = []): void {
        unset($this->results[$element]);
        $this->results[$element] = [
            'id' => self::newResultId(),
            'html' => $html,
            'command' => $meta['command'] ?? '',
            'notes' => $meta['notes'] ?? [],
            'warnings' => $meta['warnings'] ?? [],
            'elapsed' => $meta['elapsed'] ?? 0.0,
            'rows' => $meta['rows'] ?? 0,
            'ts' => $meta['ts'] ?? time(),
            'from' => $meta['from'] ?? 0,
            'to' => $meta['to'] ?? 0,
            'live' => $meta['live'] ?? false,
            'fingerprint' => $meta['fingerprint'] ?? '',
            'scope' => $meta['scope'] ?? '',
            'inputs' => $meta['inputs'] ?? self::emptyInputs(),
        ];
        $this->results = \array_slice($this->results, -self::MAX_RESULTS, null, true);
        $this->lastElement = $element;
    }

    /** Forgets the run of `$element` after a failed rerun; '' is a failure of no statistic, which forgets none. */
    public function clearResult(string $element): void {
        unset($this->results[$element]);
        $this->lastElement = $element;
    }

    /** Keeps the run of `$element` after a cancelled rerun; the notices are its now. */
    public function keepResult(string $element): void {
        $this->lastElement = $element;
    }

    /** A result's warnings share the notices' dismiss action. */
    public function dismiss(string $id): void {
        parent::dismiss($id);
        foreach ($this->results as $element => $result) {
            $this->results[$element]['warnings'] = array_values(array_filter($result['warnings'], static fn (array $n): bool => $n['id'] !== $id));
        }
    }

    /** @return null|StatsResult */
    public function result(string $element): ?array {
        return $this->results[$element] ?? null;
    }

    /** @param PanelResult $panel */
    public function setPanel(string $name, array $panel): void {
        if (\in_array($name, self::PANELS, true)) {
            $this->panels[$name] = $panel;
        }
    }

    /** @return null|PanelResult */
    public function panel(string $name): ?array {
        return $this->panels[$name] ?? null;
    }

    public function snapshot(): array {
        if ($this->isEmpty()) {
            return [];
        }

        return [
            'results' => \array_slice($this->results, -self::SNAPSHOT_RESULTS, null, true),
            'panels' => $this->panels,
            'lastElement' => $this->lastElement,
            'notifications' => $this->notifications,
        ];
    }

    public function restore(array $data): void {
        $this->results = [];
        foreach (\is_array($data['results'] ?? null) ? $data['results'] : [] as $element => $result) {
            $valid = \is_string($element) ? self::resultFrom($result) : null;
            if ($valid !== null) {
                $this->results[$element] = $valid;
            }
        }

        $this->panels = [];
        foreach (\is_array($data['panels'] ?? null) ? $data['panels'] : [] as $name => $panel) {
            $valid = \is_string($name) && \in_array($name, self::PANELS, true) ? self::panelFrom($panel) : null;
            if ($valid !== null) {
                $this->panels[$name] = $valid;
            }
        }

        $this->lastElement = self::stringFrom($data['lastElement'] ?? '');
        $this->notifications = self::notificationsFrom($data['notifications'] ?? []);
    }

    public function isEmpty(): bool {
        return $this->results === [] && $this->panels === [];
    }

    /** @return StatsInputs */
    public static function emptyInputs(): array {
        return ['count' => 0, 'order' => '', 'filter' => '', 'lower' => '', 'upper' => '', 'aggregation' => []];
    }

    /** @return null|StatsResult */
    private static function resultFrom(mixed $r): ?array {
        if (!\is_array($r) || !\is_string($r['id'] ?? null) || !\is_string($r['html'] ?? null)) {
            return null;
        }

        return [
            'id' => $r['id'],
            'html' => $r['html'],
            'command' => self::stringFrom($r['command'] ?? ''),
            'notes' => self::linesFrom($r['notes'] ?? []),
            'warnings' => self::notificationsFrom($r['warnings'] ?? []),
            'elapsed' => \is_float($r['elapsed'] ?? null) ? $r['elapsed'] : 0.0,
            'rows' => self::intFrom($r['rows'] ?? 0),
            'ts' => self::intFrom($r['ts'] ?? 0),
            'from' => self::intFrom($r['from'] ?? 0),
            'to' => self::intFrom($r['to'] ?? 0),
            'live' => ($r['live'] ?? false) === true,
            'fingerprint' => self::stringFrom($r['fingerprint'] ?? ''),
            'scope' => self::stringFrom($r['scope'] ?? ''),
            'inputs' => self::inputsFrom($r['inputs'] ?? []),
        ];
    }

    /** @return null|PanelResult */
    private static function panelFrom(mixed $p): ?array {
        if (!\is_array($p) || !\is_array($p['bars'] ?? null)) {
            return null;
        }

        $bars = [];
        foreach ($p['bars'] as $bar) {
            if (\is_array($bar) && \is_string($bar['key'] ?? null) && \is_string($bar['label'] ?? null)) {
                $bars[] = [
                    'key' => $bar['key'],
                    'label' => $bar['label'],
                    'bytes' => self::intFrom($bar['bytes'] ?? 0),
                    'share' => \is_float($bar['share'] ?? null) ? $bar['share'] : null,
                    'series' => \is_int($bar['series'] ?? null) ? $bar['series'] : null,
                ];
            }
        }
        $inputs = \is_array($p['inputs'] ?? null) ? $p['inputs'] : [];

        return [
            'bars' => $bars,
            'command' => self::stringFrom($p['command'] ?? ''),
            'error' => self::stringFrom($p['error'] ?? ''),
            'elapsed' => \is_float($p['elapsed'] ?? null) ? $p['elapsed'] : 0.0,
            'ts' => self::intFrom($p['ts'] ?? 0),
            'from' => self::intFrom($p['from'] ?? 0),
            'to' => self::intFrom($p['to'] ?? 0),
            'live' => ($p['live'] ?? false) === true,
            'fingerprint' => self::stringFrom($p['fingerprint'] ?? ''),
            'scope' => self::stringFrom($p['scope'] ?? ''),
            'inputs' => [
                'filter' => self::stringFrom($inputs['filter'] ?? ''),
                'lower' => self::stringFrom($inputs['lower'] ?? ''),
                'upper' => self::stringFrom($inputs['upper'] ?? ''),
            ],
            'clamp' => self::stringFrom($p['clamp'] ?? ''),
        ];
    }

    /** @return StatsInputs */
    private static function inputsFrom(mixed $i): array {
        if (!\is_array($i)) {
            return self::emptyInputs();
        }

        $aggregation = [];
        foreach (\is_array($i['aggregation'] ?? null) ? $i['aggregation'] : [] as $key => $value) {
            if (\is_string($key) && (\is_bool($value) || \is_string($value))) {
                $aggregation[$key] = $value;
            }
        }

        return [
            'count' => self::intFrom($i['count'] ?? 0),
            'order' => self::stringFrom($i['order'] ?? ''),
            'filter' => self::stringFrom($i['filter'] ?? ''),
            'lower' => self::stringFrom($i['lower'] ?? ''),
            'upper' => self::stringFrom($i['upper'] ?? ''),
            'aggregation' => $aggregation,
        ];
    }

    /** @return list<string> */
    private static function linesFrom(mixed $value): array {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function intFrom(mixed $value): int {
        return \is_int($value) ? $value : 0;
    }
}
