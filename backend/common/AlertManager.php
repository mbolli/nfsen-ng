<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\MultiStatCsvParser;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\store\AlertEventRepository;
use mbolli\nfsen_ng\store\AlertSampleRepository;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\StoreUnavailableException;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;

/**
 * Evaluates alert rules once per data interval and dispatches notifications. Rule state lives in
 * alerts-state.json, the event history and the filtered rules' samples in SQLite. One instance
 * lives as long as the server process.
 *
 * @phpstan-import-type AlertEvent from AlertEventRepository
 * @phpstan-import-type LegacyEntry from AlertEventRepository
 *
 * @phpstan-type SlotValues array{flows: float, packets: float, bytes: float}
 * @phpstan-type Measurement array{values: ?SlotValues, threshold: ?float, reason: string}
 * @phpstan-type RenderedTemplates array{title: string, message: string, subject: string, body: string}
 * @phpstan-type Delivery array{status: string, detail: string}
 * @phpstan-type Deliveries array{email: Delivery, webhook: Delivery}
 * @phpstan-type TestResult array{fired: bool, evaluated: bool, value: float, threshold: ?float, condition: string,
 *                                reason: string, slot: int, delivery: Deliveries, title: string, message: string, subject: string, body: string}
 */
final class AlertManager {
    public const DEFAULT_EMAIL_SUBJECT = '[nfsen-ng] Alert: {rule}';
    public const DEFAULT_EMAIL_BODY = "Alert rule \"{rule}\" fired.\n\nMetric:  {metric}\nValue:   {value}\nProfile: {profile}\nSources: {sources}\nTime:    {time} UTC\n";
    public const DEFAULT_WEBHOOK_TITLE = 'nfsen-ng alert: {rule}';
    public const DEFAULT_WEBHOOK_MESSAGE = '{metric} = {value} (profile: {profile}, sources: {sources})';

    /** Length of one nfcapd data interval. */
    public const int SLOT_SECONDS = 300;

    /** How long one live evaluation waits for nfdump slots in total. The import daemon waits for it. */
    public const float LIVE_SLOT_BUDGET_SECONDS = 60.0;

    /** Delivery statuses: sent, failed (the detail says why), not set up, or not tried because the rule did not fire. */
    public const string DELIVERY_SENT = 'sent';

    public const string DELIVERY_FAILED = 'failed';

    public const string DELIVERY_UNCONFIGURED = 'unconfigured';

    public const string DELIVERY_SKIPPED = 'skipped';

    /** How long a webhook may take to answer. */
    public const int WEBHOOK_TIMEOUT_SECONDS = 10;

    /** A last evaluated slot this far in the future, followed by one that is not, means the clock or NFCAPD_TZ moved back. */
    private const int REWIND_SECONDS = 3600;

    /** How long a slot waits for a source whose capture files are still being imported (the hourly catch-up of a new day directory). */
    private const int CATCH_UP_SECONDS = 7200;

    private const array ZERO = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];

    /** A filtered rule's totals: at most one row per protocol, however many flows match. */
    private const string TOTALS_STATISTIC = 'proto';

    /** @var array<string, AlertState> Keyed by rule ID */
    private array $states = [];

    /** @var array<string, int> rule ID => bumped whenever its state is dropped, so an evaluation in flight discards its result */
    private array $generation = [];

    /**
     * Slots not evaluated yet, with the sources that reported them and each source's stored values
     * read right after its import (a string says why they cannot be read, null: not read yet).
     *
     * @var array<string, array<int, array<string, null|SlotValues|string>>> profile => slot => source => values
     */
    private array $pending = [];

    /** @var array<string, array<string, int>> profile => source => newest file timestamp it reported */
    private array $newestBySource = [];

    /** @var array<string, array<string, true>> profile => sources left out because nothing of theirs was waiting, until they report again */
    private array $down = [];

    /** @var array<string, int> profile => newest file timestamp onFileImported() has seen */
    private array $lastSeenTs = [];

    /** @var array<string, int> profile => newest slot onFileImported() has evaluated */
    private array $evaluatedSlot = [];

    private string $eventsError = '';

    private bool $eventsWarned = false;

    private bool $stateWriteFailed = false;

    private bool $stopped = false;

    /** The rule IDs whose leftovers were last swept (see dropMissingRules()); null: not yet. */
    private ?string $sweptRules = null;

    /** @var array<string, string> rule ID => the sample fingerprint the last sweep kept */
    private array $sweptFingerprints = [];

    /**
     * @param null|AlertEventRepository  $events  null: opened from Database::shared() on first use
     * @param null|AlertSampleRepository $samples null: opened from Database::shared() on first use
     */
    public function __construct(
        private readonly Datasource $db,
        private readonly string $statePath,
        private readonly string $logPath,
        private readonly string $emailFrom,
        private ?AlertEventRepository $events = null,
        private ?AlertSampleRepository $samples = null,
    ) {
        $this->loadState();
    }

    // ── Scheduling and evaluation ──────────────────────────────────────────────

    /**
     * From shutdown: evaluates no further slot, since a stop cuts file walks short and a source
     * still importing would look down.
     */
    public function stop(): void {
        $this->stopped = true;
    }

    public function isStopped(): bool {
        return $this->stopped;
    }

    /**
     * Evaluates each slot of the profile once, in order, when every configured source has reported
     * it or a newer file, whatever the arrival order (D17). A source with nothing waiting on disk
     * once a later interval is in counts as down and is left out until it reports again.
     *
     * @param list<AlertRule> $rules  every rule; the state of a rule missing from the list is dropped
     * @param null|string     $source the source that imported the file; null lets $isLastSource stand for every source
     *
     * @return list<string> names of the rules that fired
     */
    public function onFileImported(array $rules, string $profile, int $fileTs, bool $isLastSource, ?string $source = null): array {
        if ($this->stopped) {
            return [];
        }
        $this->dropMissingRules($rules);
        // Taken before the first read can yield: a forget() after it discards that rule's evaluation.
        $generations = $this->generation;
        $lastSeen = $this->lastSeenTs[$profile] ?? null;
        if ($lastSeen !== null && self::rewound($lastSeen, $fileTs)) {
            unset($this->pending[$profile], $this->newestBySource[$profile], $this->down[$profile], $this->lastSeenTs[$profile], $this->evaluatedSlot[$profile]);
        }

        $sources = self::configuredSources();
        $open = $fileTs > ($this->evaluatedSlot[$profile] ?? PHP_INT_MIN);
        foreach ($source !== null ? [$source] : ($isLastSource ? $sources : []) as $reporter) {
            $this->newestBySource[$profile][$reporter] = max($fileTs, $this->newestBySource[$profile][$reporter] ?? $fileTs);
            unset($this->down[$profile][$reporter]);
            if ($open) {
                $this->pending[$profile][$fileTs][$reporter] = $reporter === $source && $this->readsStore($rules, $profile, $reporter)
                    ? $this->readAfterImport($reporter, $profile, $fileTs)
                    : null;
            }
        }
        if ($open) {
            $this->pending[$profile][$fileTs] ??= [];
            ksort($this->pending[$profile]);
        }
        $this->lastSeenTs[$profile] = max($fileTs, $this->lastSeenTs[$profile] ?? $fileTs);

        $fired = self::inBackground(function () use ($rules, $profile, $sources, $generations): array {
            $fired = [];
            foreach (array_keys($this->pending[$profile] ?? []) as $slot) {
                if ($this->stopped || !$this->complete($profile, $slot, $sources)) {
                    break;
                }
                $reports = $this->pending[$profile][$slot];
                unset($this->pending[$profile][$slot]);
                $this->evaluatedSlot[$profile] = max($slot, $this->evaluatedSlot[$profile] ?? $slot);
                $fired = [...$fired, ...$this->evaluateRules($rules, $profile, $slot, $reports, $generations)];
            }

            return $fired;
        });

        return array_values(array_unique($fired));
    }

    /**
     * Evaluates every enabled rule of the profile for the data interval starting at $slot, reading
     * each source's newest stored interval for rules without a traffic filter.
     *
     * @param list<AlertRule> $rules
     *
     * @return list<string> names of the rules that fired
     */
    public function runPeriodic(array $rules, string $profile, int $slot): array {
        return self::inBackground(fn (): array => $this->evaluateRules($rules, $profile, $slot, null, $this->generation));
    }

    /**
     * The live evaluation of the newest complete slot (same sources, same baseline) without touching
     * state or samples: records a 'test' event, and notifies when the condition holds, waiting for
     * the webhook's answer. Renders the templates either way; a filtered rule's nfdump is interactive.
     *
     * @return TestResult
     */
    public function testRule(AlertRule $rule, string $profile): array {
        [$slot, $reports] = $rule->nfdumpFilter === null ? $this->storedSlot($rule, $profile) : [$this->newestSlot($rule, $profile), null];
        $measured = $this->measure($rule, $profile, $slot, $reports);
        $values = $measured['values'] ?? self::ZERO;
        $threshold = $measured['threshold'];
        $evaluated = $measured['values'] !== null && $threshold !== null;
        $value = $values[$rule->metric] ?? 0.0;
        $fired = $evaluated && $this->evaluate($rule->operator, $value, $threshold);

        $vars = self::buildTemplateVars($rule, $values, $threshold ?? PHP_FLOAT_MAX, $slot);
        if ($evaluated) {
            $this->recordEvent('test', $rule, $value, $threshold, time(), $profile);
        }

        return [
            'fired' => $fired,
            'evaluated' => $evaluated,
            'value' => $value,
            'threshold' => $threshold,
            'condition' => $vars['{condition}'],
            'reason' => $measured['reason'],
            'slot' => $slot,
            'delivery' => $fired ? $this->notify($rule, $values, $vars, $slot, await: true) : $this->unsent($rule),
        ] + self::renderTemplates($rule, $vars);
    }

    /**
     * Drops the rule's state, even from an evaluation in flight; a firing rule gets a 'resolved' event.
     * A rule gone from the settings was deleted: its samples and 'test' events go too.
     */
    public function forget(string $ruleId, ?AlertRule $rule = null): void {
        $known = isset($this->states[$ruleId]);
        $this->release($ruleId, $rule);
        if ($known) {
            $this->saveState();
        }
        if (self::configuredRule($ruleId) === null) {
            $this->purge($ruleId);
        }
    }

    /** @return array<string, AlertState> copies, keyed by rule ID */
    public function states(): array {
        return array_map(static fn (AlertState $state): AlertState => clone $state, $this->states);
    }

    public function firingCount(): int {
        return \count(array_filter($this->states, static fn (AlertState $state): bool => $state->firing));
    }

    /**
     * Fetch the current metric slot for a rule, honouring its optional nfdumpFilter, from the same
     * slot and sources as testRule(). Zeros when the values cannot be read.
     *
     * @return SlotValues
     */
    public function fetchCurrentSlot(AlertRule $rule, string $profile): array {
        $values = $rule->nfdumpFilter === null
            ? $this->storedValues($rule, $profile, ...$this->storedSlot($rule, $profile))
            : $this->fetchFilteredSlot($rule, $profile, $this->newestSlot($rule, $profile));

        return \is_array($values) ? $values : self::ZERO;
    }

    /**
     * The rule's threshold for the interval starting at $slot; PHP_FLOAT_MAX while a
     * percent-of-average rule has no baseline yet (see baseline()).
     */
    public function computeThreshold(AlertRule $rule, int $slot): float {
        $threshold = $this->threshold($rule, $slot);

        return \is_string($threshold) ? PHP_FLOAT_MAX : $threshold;
    }

    /**
     * Records an event of $kind and sends the email and, in a coroutine of its own, the webhook.
     *
     * @param SlotValues $values
     */
    public function dispatchNotifications(AlertRule $rule, array $values, float $threshold, int $ts, string $kind = 'test'): void {
        $this->recordEvent($kind, $rule, $values[$rule->metric] ?? 0.0, $threshold, $ts);
        $this->notify($rule, $values, self::buildTemplateVars($rule, $values, $threshold, $ts), $ts, await: false);
    }

    // ── History ────────────────────────────────────────────────────────────────

    /** False when the SQLite store cannot be opened; eventsError() says why. */
    public function eventsAvailable(): bool {
        return $this->events() !== null;
    }

    /** Why the history is unavailable, '' when it is available. */
    public function eventsError(): string {
        $this->events();

        return $this->eventsError;
    }

    /**
     * Newest first; empty when the history is unavailable.
     *
     * @return list<AlertEvent>
     */
    public function recentEvents(int $limit = 20, ?string $ruleId = null): array {
        try {
            return $this->events()?->recent($limit, $ruleId) ?? [];
        } catch (\Throwable $e) {
            $this->log('Alert history could not be read: ' . $e->getMessage(), LOG_WARNING);

            return [];
        }
    }

    /** @return array<string, int> ruleId => ts of the newest 'fired' event */
    public function lastFired(): array {
        try {
            return $this->events()?->lastFired() ?? [];
        } catch (\Throwable $e) {
            $this->log('Alert history could not be read: ' . $e->getMessage(), LOG_WARNING);

            return [];
        }
    }

    /**
     * The fired and test events in the shape of the old alerts-log.json entries, newest first.
     *
     * @return list<array{ts: int, rule: string, metric: string, value: float, profile: string, sources: list<string>,
     *                    kind: string, threshold: ?float, ruleId: ?string}>
     */
    public function getRecentLog(int $n = 10): array {
        try {
            $events = $this->events()?->recent(max(1, $n), null, ['fired', 'test']) ?? [];
        } catch (\Throwable $e) {
            $this->log('Alert history could not be read: ' . $e->getMessage(), LOG_WARNING);

            return [];
        }

        return array_map(static fn (array $event): array => [
            'ts' => $event['ts'],
            'rule' => $event['ruleName'],
            'metric' => $event['metric'],
            'value' => $event['value'],
            'profile' => $event['profile'],
            'sources' => $event['sources'],
            'kind' => $event['kind'],
            'threshold' => $event['threshold'],
            'ruleId' => $event['ruleId'],
        ], $events);
    }

    /**
     * Moves alerts-log.json into SQLite once: every entry becomes a migrated 'fired' event in one
     * transaction that also sets the meta key, then the file is renamed to *.migrated. Throws when
     * the import fails, which changes nothing, so the next start retries.
     *
     * @param null|list<AlertRule> $rules the current rules; null reads them from the settings
     *
     * @return int migrated entries
     */
    public function migrateLegacyLog(?array $rules = null): int {
        $events = $this->events();
        clearstatcache(true, $this->logPath);
        if ($events === null || !is_file($this->logPath)) {
            return 0;
        }
        if ($events->legacyLogMigrated()) {
            $this->renameLegacyLog();

            return 0;
        }

        $raw = @file_get_contents($this->logPath);
        if ($raw === false) {
            throw new \RuntimeException("cannot read {$this->logPath}");
        }

        $idsByName = [];
        foreach ($rules ?? (isset(Config::$settings) ? Config::$settings->alerts : []) as $rule) {
            $idsByName[$rule->name][] = $rule->id;
        }

        $decoded = json_decode($raw, true);
        $entries = [];
        foreach (\is_array($decoded) ? $decoded : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $name = \is_scalar($item['rule'] ?? null) ? (string) $item['rule'] : '';
            $ids = $idsByName[$name] ?? [];
            $entries[] = [
                'ts' => (int) ($item['ts'] ?? 0),
                'ruleId' => \count($ids) === 1 ? $ids[0] : null,
                'ruleName' => $name,
                'profile' => \is_scalar($item['profile'] ?? null) ? (string) $item['profile'] : '',
                'sources' => array_values(array_map(strval(...), array_filter((array) ($item['sources'] ?? []), is_scalar(...)))),
                'metric' => \is_scalar($item['metric'] ?? null) ? (string) $item['metric'] : 'bytes',
                'value' => is_numeric($item['value'] ?? null) ? (float) $item['value'] : 0.0,
            ];
        }

        $count = $events->importLegacyLog($entries);
        $this->renameLegacyLog();

        return $count;
    }

    // ── Templates ──────────────────────────────────────────────────────────────

    /**
     * Build the {token} to formatted-value substitution map for notification templates.
     * All three flow metrics are always exposed regardless of $rule->metric, since
     * fetchCurrentSlot() already computes all three in one nfdump/RRD round-trip.
     *
     * @param SlotValues $values
     *
     * @return array<string, string>
     */
    public static function buildTemplateVars(AlertRule $rule, array $values, float $threshold, int $ts): array {
        $thresholdDisplay = $threshold === PHP_FLOAT_MAX ? '∞' : number_format($threshold, 2);

        return [
            '{rule}' => $rule->name,
            '{metric}' => $rule->metric,
            '{value}' => number_format((float) ($values[$rule->metric] ?? 0.0), 2),
            '{threshold}' => $thresholdDisplay,
            '{operator}' => $rule->operator,
            '{condition}' => $rule->metric . ' ' . $rule->operator . ' ' . $thresholdDisplay,
            '{flows}' => number_format((float) ($values['flows'] ?? 0.0), 2),
            '{packets}' => number_format((float) ($values['packets'] ?? 0.0), 2),
            '{bytes}' => number_format((float) ($values['bytes'] ?? 0.0), 2),
            '{profile}' => $rule->profile,
            // No source selected means every configured source.
            '{sources}' => implode(', ', array_map(Config::$settings->sourceName(...), $rule->sources !== [] ? $rule->sources : self::configuredSources())),
            '{time}' => gmdate('Y-m-d H:i:s', $ts),
        ];
    }

    /**
     * Resolve the effective template string: per-rule override, then global default, then built-in default.
     */
    public static function resolveTemplate(?string $ruleTemplate, string $globalTemplate, string $builtinDefault): string {
        if ($ruleTemplate !== null && $ruleTemplate !== '') {
            return $ruleTemplate;
        }

        return $globalTemplate !== '' ? $globalTemplate : $builtinDefault;
    }

    /**
     * The webhook title and message and the email subject and body, as a notification sends them.
     *
     * @param array<string, string> $vars from buildTemplateVars()
     *
     * @return RenderedTemplates
     */
    public static function renderTemplates(AlertRule $rule, array $vars): array {
        $settings = isset(Config::$settings) ? Config::$settings : null;

        return [
            'title' => strtr(self::resolveTemplate($rule->webhookTitleTemplate, $settings->defaultWebhookTitleTemplate ?? '', self::DEFAULT_WEBHOOK_TITLE), $vars),
            'message' => strtr(self::resolveTemplate($rule->webhookMessageTemplate, $settings->defaultWebhookMessageTemplate ?? '', self::DEFAULT_WEBHOOK_MESSAGE), $vars),
            'subject' => strtr(self::resolveTemplate($rule->emailSubjectTemplate, $settings->defaultEmailSubjectTemplate ?? '', self::DEFAULT_EMAIL_SUBJECT), $vars),
            'body' => strtr(self::resolveTemplate($rule->emailBodyTemplate, $settings->defaultEmailBodyTemplate ?? '', self::DEFAULT_EMAIL_BODY), $vars),
        ];
    }

    /**
     * Parse a window string to seconds.
     * Supported: 10m, 30m, 1h, 6h, 12h, 24h.
     */
    public function parseWindow(string $window): int {
        return match ($window) {
            '10m' => 600,
            '30m' => 1800,
            '1h' => 3600,
            '6h' => 21600,
            '12h' => 43200,
            '24h' => 86400,
            default => 3600,
        };
    }

    // ── Persistence ────────────────────────────────────────────────────────────

    public function saveState(): void {
        $data = [];
        foreach ($this->states as $id => $state) {
            $data[$id] = $state->toArray();
        }

        $this->atomicWrite($this->statePath, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Flows, in-packets and in-bytes summed over the rows of nfdump's `-s proto` CSV, whose default
     * order prints the in-direction counters; other lines add nothing.
     *
     * @return SlotValues
     */
    public static function sumProtocolRows(string $csv): array {
        $sum = self::ZERO;
        foreach (MultiStatCsvParser::parse($csv, [self::TOTALS_STATISTIC])[0] ?? [] as $row) {
            $sum['flows'] += $row['flows'];
            $sum['packets'] += $row['packets'];
            $sum['bytes'] += $row['bytes'];
        }

        return $sum;
    }

    /** A parsed window as the Alerts page names it: '10 min', '1 h'. */
    private static function windowLabel(int $seconds): string {
        return $seconds < 3600 ? intdiv($seconds, 60) . ' min' : intdiv($seconds, 3600) . ' h';
    }

    /**
     * The live evaluation: filtered rules' nfdump runs are background work. Together they wait
     * LIVE_SLOT_BUDGET_SECONDS at most behind user queries; a rule left without a slot is skipped.
     *
     * @param \Closure(): list<string> $work
     *
     * @return list<string> names of the rules that fired
     */
    private static function inBackground(\Closure $work): array {
        return NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, $work, budget: self::LIVE_SLOT_BUDGET_SECONDS);
    }

    /**
     * @param list<AlertRule>                            $rules
     * @param null|array<string, null|SlotValues|string> $reports     the sources that reported the slot (see $pending); null: runPeriodic()
     * @param array<string, int>                         $generations $generation when the caller got $rules
     *
     * @return list<string> names of the rules that fired
     */
    private function evaluateRules(array $rules, string $profile, int $slot, ?array $reports, array $generations): array {
        $fired = [];
        $changed = false;

        foreach ($rules as $rule) {
            if ($this->stopped) {
                break;
            }
            $generation = $generations[$rule->id] ?? 0;
            if ($rule->profile !== $profile || ($this->generation[$rule->id] ?? 0) !== $generation) {
                // A changed generation: forget() ran since $rules was read, so this copy of the rule is stale.
                continue;
            }

            $state = $this->states[$rule->id] ?? null;
            if (!$rule->enabled) {
                if ($state !== null && $state->firing) {
                    $this->release($rule->id, $rule, $slot);
                    $changed = true;
                }

                continue;
            }

            $state ??= AlertState::initial();
            if (self::alreadyEvaluated($state, $slot)) {
                continue;
            }

            $measured = $this->measure($rule, $profile, $slot, $reports);
            if (($this->generation[$rule->id] ?? 0) !== $generation) {
                continue;
            }
            // Recorded with or without a baseline: the recorded values are what builds one.
            if ($rule->nfdumpFilter !== null && $measured['values'] !== null) {
                $this->recordSample($rule, $slot, $measured['values'][$rule->metric] ?? 0.0);
            }
            if ($measured['values'] === null || $measured['threshold'] === null) {
                $this->log("Alert '{$rule->name}': slot " . gmdate('Y-m-d H:i', $slot) . " UTC not evaluated: {$measured['reason']}", LOG_INFO);

                continue;
            }

            $values = $measured['values'];
            $threshold = $measured['threshold'];
            $value = $values[$rule->metric] ?? 0.0;

            $state->lastEvaluatedSlot = $slot;
            $state->lastValue = $value;
            if ($state->cooldownRemaining > 0) {
                --$state->cooldownRemaining;
            }

            if ($this->evaluate($rule->operator, $value, $threshold)) {
                if (!$state->firing || $state->cooldownRemaining === 0) {
                    $notify = $state->cooldownRemaining === 0;
                    if (!$state->firing) {
                        $state->firing = true;
                        $state->firedAt = $slot;
                    }
                    $state->lastTriggeredAt = $slot;
                    $state->recentTriggers = \array_slice([...$state->recentTriggers, $slot], -10);
                    $state->cooldownRemaining = $rule->cooldownSlots;

                    if ($notify) {
                        $this->dispatchNotifications($rule, $values, $threshold, $slot, 'fired');
                    } else {
                        $this->recordEvent('fired', $rule, $value, $threshold, $slot);
                    }
                    $fired[] = $rule->name;
                }
            } elseif ($state->firing) {
                $state->firing = false;
                $state->firedAt = null;
                $this->recordEvent('resolved', $rule, $value, $threshold, $slot);
            }

            $this->states[$rule->id] = $state;
            $changed = true;
        }

        if ($changed) {
            $this->saveState();
        }
        $this->pruneSamples($rules, $profile, $slot);

        return $fired;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * Drops what belongs to rules no longer in the list: their state, and, whenever the list
     * changed, the samples and 'test' events of every rule not in it. A rule whose fingerprint
     * changed since the last sweep loses its samples of the old one.
     *
     * @param list<AlertRule> $rules
     */
    private function dropMissingRules(array $rules): void {
        $ids = array_values(array_unique(array_map(static fn (AlertRule $rule): string => $rule->id, $rules)));
        $missing = array_diff_key($this->states, array_flip($ids));
        foreach (array_keys($missing) as $ruleId) {
            $this->release((string) $ruleId, null);
        }
        if ($missing !== []) {
            $this->saveState();
        }

        sort($ids);
        $key = implode("\n", $ids);
        $fingerprints = [];
        foreach ($rules as $rule) {
            $fingerprints[$rule->id] = $this->fingerprint($rule);
        }
        $edited = array_diff_assoc($fingerprints, $this->sweptFingerprints);
        $samples = $this->samples();
        $events = $this->events();
        if (($key === $this->sweptRules && $edited === []) || $samples === null || $events === null) {
            return;
        }

        try {
            $samples->keepOnly($ids);
            if ($edited !== []) {
                $samples->keepFingerprints($edited);
            }
            $events->deleteForOtherRules($ids, ['test']);
            $this->sweptRules = $key;
            $this->sweptFingerprints = $fingerprints;
        } catch (\Throwable $e) {
            $this->log('Alert samples and test events of deleted or edited rules could not be dropped: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Drops the samples of the profile's rules that no averaging window reaches from $slot on,
     * including those of rules that no longer record.
     *
     * @param list<AlertRule> $rules
     */
    private function pruneSamples(array $rules, string $profile, int $slot): void {
        $ids = array_values(array_unique(array_map(
            static fn (AlertRule $rule): string => $rule->id,
            array_filter($rules, static fn (AlertRule $rule): bool => $rule->profile === $profile),
        )));

        try {
            $this->samples()?->prune($ids, $slot - AlertSampleRepository::KEEP_SECONDS);
        } catch (\Throwable $e) {
            $this->log('Alert samples older than ' . gmdate('Y-m-d H:i', $slot - AlertSampleRepository::KEEP_SECONDS) . ' UTC could not be dropped: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /** A deleted rule's samples and 'test' events; its fired and resolved events stay in the history. */
    private function purge(string $ruleId): void {
        try {
            $this->samples()?->deleteRule($ruleId);
            $this->events()?->deleteForRule($ruleId, ['test']);
        } catch (\Throwable $e) {
            $this->log("Alert {$ruleId}: dropping its samples and test events failed: " . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * A filtered rule's value of $slot, which its percent-of-average baseline averages; only the live
     * evaluation records. Its later samples go only when the timeline moved back (see rewound()).
     */
    private function recordSample(AlertRule $rule, int $slot, float $value): void {
        try {
            $this->samples()?->record($rule->id, $this->fingerprint($rule), $slot, $value, self::horizon());
        } catch (\Throwable $e) {
            $this->log("Alert '{$rule->name}': recording the value of " . gmdate('Y-m-d H:i', $slot) . ' UTC failed: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    private function fingerprint(AlertRule $rule): string {
        return $rule->sampleFingerprint($this->sourcesOf($rule));
    }

    /** The threshold for $slot, or why there is none. */
    private function threshold(AlertRule $rule, int $slot): float|string {
        if ($rule->thresholdType !== 'percent_of_avg') {
            return $rule->thresholdValue;
        }
        $baseline = $this->baseline($rule, $slot);

        return \is_string($baseline) ? $baseline : $baseline * ($rule->thresholdValue / 100.0);
    }

    /**
     * In the values' unit, over the window before $slot: a filtered rule's own recorded values (one
     * at least), else the datasource's average of its sources. A zero average is no baseline either.
     *
     * @return float|string the average, or why there is none
     */
    private function baseline(AlertRule $rule, int $slot): float|string {
        $window = $this->parseWindow($rule->avgWindow);
        $average = 'the ' . self::windowLabel($window) . ' average';

        if ($rule->nfdumpFilter !== null) {
            $samples = $this->samples();
            if ($samples === null) {
                return "no baseline for {$average} of the filter's traffic: the alert store is unavailable ({$this->eventsError})";
            }
            $recorded = $samples->average($rule->id, $this->fingerprint($rule), $slot - $window, $slot);
            if ($recorded['count'] === 0) {
                return "no baseline yet for {$average} of the filter's own traffic, which an enabled rule records at each check";
            }

            return $recorded['average'] > 0.0 ? $recorded['average'] : "no baseline while {$average} of the filter's own traffic is zero";
        }

        $value = $this->db->fetchRollingAverage($this->sourcesOf($rule), $rule->profile, $window, $slot)[$rule->metric] ?? 0.0;

        return $value > 0.0 ? $value : "no baseline yet for {$average}";
    }

    /**
     * Whether every configured source has reported $slot, reported a newer file, or is down. A
     * slot more than CATCH_UP_SECONDS behind the newest file stops waiting.
     *
     * @param list<string> $sources
     */
    private function complete(string $profile, int $slot, array $sources): bool {
        $lastSeen = $this->lastSeenTs[$profile] ?? $slot;
        if ($slot < $lastSeen - self::CATCH_UP_SECONDS) {
            return true;
        }

        foreach ($sources as $source) {
            if (\array_key_exists($source, $this->pending[$profile][$slot] ?? []) || ($this->newestBySource[$profile][$source] ?? PHP_INT_MIN) > $slot) {
                continue;
            }
            $givenUp = $lastSeen > $slot || isset($this->down[$profile][$source]);
            if (!$givenUp || $this->filesWaiting($profile, $source, $slot)) {
                return false;
            }
            $this->down[$profile][$source] = true;
        }

        return true;
    }

    /** Whether the source has an unreported capture file up to $slot on disk: its catch-up is still running, it is not down. */
    private function filesWaiting(string $profile, string $source, int $slot): bool {
        $newest = $this->newestBySource[$profile][$source] ?? null;
        $from = $newest === null ? $slot : max($newest + 1, $slot - self::CATCH_UP_SECONDS);

        try {
            return NfcapdFiles::names($from, $slot, $source, $profile) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether an enabled rule of the profile without a traffic filter covers the source.
     *
     * @param list<AlertRule> $rules
     */
    private function readsStore(array $rules, string $profile, string $source): bool {
        foreach ($rules as $rule) {
            if ($rule->enabled && $rule->profile === $profile && $rule->nfdumpFilter === null && \in_array($source, $this->sourcesOf($rule), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read right after the import, while $slot is the source's newest stored interval: a catch-up
     * imports one source after the other, so by evaluation time it may no longer be.
     *
     * @return null|SlotValues|string null when the read failed, so it is tried again at evaluation
     */
    private function readAfterImport(string $source, string $profile, int $slot): array|string|null {
        try {
            return $this->readStored($source, $profile, $slot);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return SlotValues|string a string when the store's newest interval of the source is not $slot
     */
    private function readStored(string $source, string $profile, int $slot): array|string {
        $stored = $this->db->last_update($source, 0, $profile);
        if ($stored > $slot) {
            return "the stored traffic of {$source} is already past this interval";
        }
        // 0: the datasource cannot tell, so its newest row is read as before.
        if ($stored > 0 && $stored < $slot) {
            return "the traffic of {$source} for this interval was not stored";
        }

        return $this->db->fetchLatestSlot([$source], $profile);
    }

    /**
     * The newest stored interval of the rule's sources and the sources that hold it, chosen like
     * newestSlot() chooses a capture file: a source more than one interval behind is down. Null
     * sources when the store cannot tell (every last_update() is 0).
     *
     * @return array{int, null|array<string, null>}
     */
    private function storedSlot(AlertRule $rule, string $profile): array {
        $stored = [];
        foreach ($this->sourcesOf($rule) as $source) {
            try {
                $ts = $this->db->last_update($source, 0, $profile);
            } catch (\Throwable) {
                $ts = 0;
            }
            if ($ts > 0) {
                $stored[$source] = $ts;
            }
        }
        if ($stored === []) {
            return [$this->newestSlot($rule, $profile), null];
        }

        $top = max($stored);
        $recent = array_filter($stored, static fn (int $ts): bool => $ts >= $top - self::SLOT_SECONDS);

        return [min($top, ...array_values($recent)), array_fill_keys(array_keys($recent), null)];
    }

    /** A slot up to the last evaluated one is skipped, unless the timeline moved back in between. */
    private static function alreadyEvaluated(AlertState $state, int $slot): bool {
        $last = $state->lastEvaluatedSlot;

        return $last !== null && $slot <= $last && ($slot === $last || !self::rewound($last, $slot));
    }

    /**
     * Whether $slot after $last means the clock or NFCAPD_TZ moved back: $last lies more than an
     * hour ahead of now and $slot does not. A timeline that is always ahead is no rewind.
     */
    private static function rewound(int $last, int $slot): bool {
        $horizon = self::horizon();

        return $slot < $last && $last > $horizon && $slot <= $horizon;
    }

    /** A slot after this lies more than REWIND_SECONDS ahead of the clock. */
    private static function horizon(): int {
        return time() + self::REWIND_SECONDS;
    }

    /**
     * Current values and threshold for a slot. Either is null when the rule cannot be evaluated
     * (no free nfdump process, a failed query, no baseline yet); $reason then says why.
     *
     * @param null|array<string, null|SlotValues|string> $reports see evaluateRules()
     *
     * @return Measurement
     */
    private function measure(AlertRule $rule, string $profile, int $slot, ?array $reports): array {
        $values = null;
        $threshold = null;
        $reason = '';

        try {
            $fetched = $rule->nfdumpFilter !== null
                ? $this->fetchFilteredSlot($rule, $profile, $slot)
                : $this->storedValues($rule, $profile, $slot, $reports);
            if (\is_string($fetched)) {
                $reason = $fetched;
            } else {
                $values = $fetched;
            }
        } catch (\Throwable $e) {
            $reason = 'reading the traffic failed: ' . $e->getMessage();
        }

        try {
            $computed = $this->threshold($rule, $slot);
            if (\is_string($computed)) {
                $reason = $reason !== '' ? $reason : $computed;
            } else {
                $threshold = $computed;
            }
        } catch (\Throwable $e) {
            $reason = $reason !== '' ? $reason : 'reading the average failed: ' . $e->getMessage();
        }

        return ['values' => $values, 'threshold' => $threshold, 'reason' => $reason];
    }

    /**
     * The stored values of the slot, summed over the rule's sources that reported it, like the
     * filtered path sums the sources that have its capture file. Without reports (runPeriodic(), or
     * a store that cannot tell which interval it holds) every source's newest stored interval is summed.
     *
     * @param null|array<string, null|SlotValues|string> $reports
     *
     * @return SlotValues|string
     */
    private function storedValues(AlertRule $rule, string $profile, int $slot, ?array $reports): array|string {
        if ($reports === null) {
            return $this->db->fetchLatestSlot($this->sourcesOf($rule), $profile);
        }

        $sum = null;
        foreach ($this->sourcesOf($rule) as $source) {
            if (!\array_key_exists($source, $reports)) {
                continue;
            }
            $values = $reports[$source] ?? $this->readStored($source, $profile, $slot);
            if (\is_string($values)) {
                return $values;
            }
            $sum ??= self::ZERO;
            foreach ($sum as $metric => $total) {
                $sum[$metric] = $total + $values[$metric];
            }
        }

        return $sum ?? 'none of its sources delivered this interval';
    }

    /**
     * Runs nfdump with the rule's filter over the capture file of $slot and sums its per-protocol
     * totals. A string says why it could not be evaluated.
     *
     * @return SlotValues|string
     */
    private function fetchFilteredSlot(AlertRule $rule, string $profile, int $slot): array|string {
        $sources = array_values(array_filter(
            $this->sourcesOf($rule),
            static fn (string $source): bool => NfcapdFiles::names($slot, $slot, $source, $profile) !== [],
        ));
        if ($sources === []) {
            $name = (new \DateTimeImmutable('@' . $slot))->setTimezone(Config::nfcapdTimezone())->format('YmdHi');

            return "no capture file nfcapd.{$name}";
        }

        try {
            // Not the shared instance: a reset() by another coroutine during the slot wait would
            // change how this run's output is decoded.
            $nfdump = new Nfdump();
            $nfdump->setProfile($profile);
            // -M must be set before -R: -R resolves file paths with the sources -M recorded.
            $nfdump->setOption('-M', implode(':', $sources));
            $nfdump->setOption('-R', [$slot, $slot]);
            $nfdump->setFilter($rule->nfdumpFilter ?? '');
            $nfdump->setOption('-s', self::TOTALS_STATISTIC);
            $nfdump->setOption('-n', 0);
            $nfdump->setOption('-o', 'csv');

            $csv = $nfdump->execute()['rawOutput'];
            if ($csv !== '' && MultiStatCsvParser::blockCount($csv) === 0) {
                return 'nfdump printed no per-protocol statistic';
            }

            return self::sumProtocolRows($csv);
        } catch (\Throwable $e) {
            return NfdumpSlots::timedOut($e) ? 'no free nfdump process' : 'nfdump failed: ' . $e->getMessage();
        }
    }

    /**
     * Start of the newest complete interval: the oldest of the sources' newest capture files, so a
     * source that has not rotated yet is not left out. A source more than one interval behind the
     * newest is down and does not hold the slot back, as in onFileImported().
     */
    private function newestSlot(AlertRule $rule, string $profile): int {
        $newest = [];

        try {
            foreach ($this->sourcesOf($rule) as $source) {
                $ts = NfcapdFiles::newest($profile, $source, 1)['ts'] ?? null;
                if ($ts !== null) {
                    $newest[] = $ts;
                }
            }
        } catch (\Throwable) {
        }
        if ($newest === []) {
            return intdiv(time(), self::SLOT_SECONDS) * self::SLOT_SECONDS - self::SLOT_SECONDS;
        }

        $top = max($newest);
        $slot = $top;
        foreach ($newest as $ts) {
            if ($ts >= $top - self::SLOT_SECONDS) {
                $slot = min($slot, $ts);
            }
        }

        return $slot;
    }

    /**
     * A rule without sources covers every configured source.
     *
     * @return list<string>
     */
    private function sourcesOf(AlertRule $rule): array {
        return $rule->sources !== [] ? array_values($rule->sources) : self::configuredSources();
    }

    /** @return list<string> */
    private static function configuredSources(): array {
        return isset(Config::$settings) ? Config::$settings->sources : [];
    }

    /**
     * Drops a rule's state; a firing rule gets a 'resolved' event with its last value. Without $ts
     * the event goes right after the last evaluated slot, on the time base of the other events.
     */
    private function release(string $ruleId, ?AlertRule $rule, ?int $ts = null): void {
        $state = $this->states[$ruleId] ?? null;
        unset($this->states[$ruleId]);
        $this->generation[$ruleId] = ($this->generation[$ruleId] ?? 0) + 1;
        if ($state === null || !$state->firing) {
            return;
        }
        $ts ??= $state->lastEvaluatedSlot !== null ? $state->lastEvaluatedSlot + self::SLOT_SECONDS : time();

        $events = $this->events();
        if ($events === null) {
            return;
        }

        try {
            $last = $events->recent(1, $ruleId)[0] ?? null;
            $rule ??= self::configuredRule($ruleId);
            $value = $state->lastValue ?? ($last['value'] ?? 0.0);
            $threshold = $last['threshold'] ?? null;
            if ($rule !== null) {
                $events->record('resolved', $ts, $ruleId, $rule->name, $rule->profile, array_values($rule->sources), $rule->metric, $rule->operator, $value, $threshold);
            } elseif ($last !== null) {
                $events->record('resolved', $ts, $ruleId, $last['ruleName'], $last['profile'], $last['sources'], $last['metric'], $last['operator'], $value, $threshold);
            }
        } catch (\Throwable $e) {
            $this->log("Alert {$ruleId}: recording the resolved event failed: " . $e->getMessage(), LOG_WARNING);
        }
    }

    private static function configuredRule(string $ruleId): ?AlertRule {
        foreach (isset(Config::$settings) ? Config::$settings->alerts : [] as $rule) {
            if ($rule->id === $ruleId) {
                return $rule;
            }
        }

        return null;
    }

    private function recordEvent(string $kind, AlertRule $rule, float $value, ?float $threshold, int $ts, ?string $profile = null): void {
        $events = $this->events();
        if ($events === null) {
            return;
        }

        try {
            $events->record(
                $kind,
                $ts,
                $rule->id,
                $rule->name,
                $profile ?? $rule->profile,
                array_values($rule->sources),
                $rule->metric,
                $rule->operator,
                $value,
                $threshold === PHP_FLOAT_MAX ? null : $threshold,
            );
        } catch (\Throwable $e) {
            $this->log("Alert '{$rule->name}': recording the {$kind} event failed: " . $e->getMessage(), LOG_WARNING);
        }
    }

    /** The repository, opened lazily; null while the store is unavailable. */
    private function events(): ?AlertEventRepository {
        if ($this->events === null) {
            $db = $this->store();
            $this->events = $db !== null ? new AlertEventRepository($db) : null;
        }

        return $this->events;
    }

    /** The filtered rules' samples, opened lazily; null while the store is unavailable. */
    private function samples(): ?AlertSampleRepository {
        if ($this->samples === null) {
            $db = $this->store();
            $this->samples = $db !== null ? new AlertSampleRepository($db) : null;
        }

        return $this->samples;
    }

    private function store(): ?Database {
        try {
            $db = Database::shared();
            $this->eventsError = '';

            return $db;
        } catch (StoreUnavailableException $e) {
            $this->eventsError = $e->reason;
            if (!$this->eventsWarned) {
                $this->eventsWarned = true;
                $this->log('Alert store unavailable, rules are still evaluated and notified, but have no history and filtered rules no baseline: ' . $e->getMessage(), LOG_WARNING);
            }

            return null;
        }
    }

    private function renameLegacyLog(): void {
        if (!@rename($this->logPath, $this->logPath . '.migrated')) {
            $this->log("Alerts: {$this->logPath} is imported but could not be renamed; it will not be imported again", LOG_WARNING);
        }
    }

    /**
     * Sends the email and the webhook the rule has. With $await the webhook's answer is waited
     * for; otherwise a coroutine of its own sends it and logs a failure.
     *
     * @param SlotValues            $values
     * @param array<string, string> $vars
     *
     * @return Deliveries
     */
    private function notify(AlertRule $rule, array $values, array $vars, int $ts, bool $await): array {
        $templates = self::renderTemplates($rule, $vars);
        $delivery = $this->unsent($rule);

        if ($delivery['email']['status'] === self::DELIVERY_SKIPPED) {
            $delivery['email'] = $this->sendEmail($rule, $templates);
        }
        if ($delivery['webhook']['status'] === self::DELIVERY_SKIPPED) {
            $delivery['webhook'] = $this->sendWebhook($rule, $values[$rule->metric] ?? 0.0, $ts, $templates, $await);
        }

        return $delivery;
    }

    /**
     * Each channel before anything is sent: not set up (for email also when the server has no
     * sender address), or not tried.
     *
     * @return Deliveries
     */
    private function unsent(AlertRule $rule): array {
        $email = $rule->notifyEmail !== null && $this->emailFrom !== '';

        return [
            'email' => self::delivery($email ? self::DELIVERY_SKIPPED : self::DELIVERY_UNCONFIGURED),
            'webhook' => self::delivery($rule->notifyWebhook !== null ? self::DELIVERY_SKIPPED : self::DELIVERY_UNCONFIGURED),
        ];
    }

    /** @return Delivery */
    private static function delivery(string $status, string $detail = ''): array {
        return ['status' => $status, 'detail' => $detail];
    }

    /**
     * @param RenderedTemplates $templates
     *
     * @return Delivery
     */
    private function sendEmail(AlertRule $rule, array $templates): array {
        $headers = "From: {$this->emailFrom}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
        $error = '';
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $sent = mail((string) $rule->notifyEmail, $templates['subject'], $templates['body'], $headers);
        } finally {
            restore_error_handler();
        }

        return $sent
            ? self::delivery(self::DELIVERY_SENT)
            : self::delivery(self::DELIVERY_FAILED, $error !== '' ? $error : 'the mail system did not accept it');
    }

    /**
     * @param RenderedTemplates $templates
     *
     * @return Delivery
     */
    private function sendWebhook(AlertRule $rule, float $value, int $ts, array $templates, bool $await): array {
        $url = (string) $rule->notifyWebhook;

        // SSRF guard: only http:// and https://
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::delivery(self::DELIVERY_FAILED, 'the URL does not start with http:// or https://');
        }

        $payload = json_encode([
            'event' => 'alert_fired',
            'rule' => $rule->name,
            'metric' => $rule->metric,
            'value' => $value,
            'profile' => $rule->profile,
            'sources' => $rule->sources,
            'ts' => $ts,
            // Gotify's POST /message requires a top-level "message" string; Apprise's
            // POST /notify/<key> requires "body" instead. Sending both lets the webhook
            // URL point directly at either (e.g. https://gotify.example/message?token=...)
            // without an intermediary.
            'title' => $templates['title'],
            'message' => $templates['message'],
            'body' => $templates['message'],
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return self::delivery(self::DELIVERY_FAILED, 'the message could not be encoded as JSON');
        }

        if (!$await && self::inCoroutine()) {
            Coroutine::create(function () use ($rule, $url, $payload): void {
                $error = $this->postWebhook($url, $payload);
                if ($error !== null) {
                    $this->log("Alert '{$rule->name}': the webhook failed: {$error}", LOG_WARNING);
                }
            });

            return self::delivery(self::DELIVERY_SENT);
        }

        $error = $this->postWebhook($url, $payload);

        return $error === null ? self::delivery(self::DELIVERY_SENT) : self::delivery(self::DELIVERY_FAILED, $error);
    }

    private static function inCoroutine(): bool {
        return class_exists(Coroutine::class) && Coroutine::getCid() > 0;
    }

    /**
     * POSTs the payload and waits for the answer: the coroutine HTTP client inside a coroutine,
     * cURL outside one (tests, CLI).
     *
     * @return null|string null when the receiver answered 2xx, otherwise why not
     */
    private function postWebhook(string $url, string $payload): ?string {
        if (!self::inCoroutine()) {
            return $this->postWebhookCurl($url, $payload);
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            return 'the URL has no host';
        }
        $ssl = strtolower($parts['scheme'] ?? '') === 'https';
        $port = (int) ($parts['port'] ?? ($ssl ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        try {
            $client = new Client($parts['host'], $port, $ssl);
            $client->set(['timeout' => self::WEBHOOK_TIMEOUT_SECONDS]);
            $client->setHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'nfsen-ng-alert/1.0',
            ]);
            $client->post($path, $payload);
            $status = $client->statusCode;
            $error = (string) $client->errMsg;
            $client->close();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return self::webhookError($status, $error);
    }

    private function postWebhookCurl(string $url, string $payload): ?string {
        $ch = curl_init($url);
        if ($ch === false) {
            return 'cURL could not start the request';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: nfsen-ng-alert/1.0'],
            CURLOPT_TIMEOUT => self::WEBHOOK_TIMEOUT_SECONDS,
        ]);
        $answered = curl_exec($ch) !== false;
        $status = $answered ? (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) : -1;
        $error = curl_error($ch);
        curl_close($ch);

        return self::webhookError($status, $error);
    }

    /** A $status of 0 or less: no answer, and $error says why. */
    private static function webhookError(int $status, string $error): ?string {
        return match (true) {
            $status >= 200 && $status < 300 => null,
            $status <= 0 => $error !== '' ? lcfirst($error) : 'no answer',
            default => "HTTP {$status}",
        };
    }

    /** Evaluate the threshold condition. */
    private function evaluate(string $operator, float $value, float $threshold): bool {
        return match ($operator) {
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '>=' => $value >= $threshold,
            '<=' => $value <= $threshold,
            default => false,
        };
    }

    private function loadState(): void {
        if (!file_exists($this->statePath)) {
            return;
        }

        $raw = json_decode((string) @file_get_contents($this->statePath), true);
        if (!\is_array($raw)) {
            return;
        }

        foreach ($raw as $id => $stateData) {
            if (\is_array($stateData)) {
                $this->states[(string) $id] = AlertState::fromArray($stateData);
            }
        }
    }

    /** A read-only state directory must not stop evaluation, so a failed write is logged once. */
    private function atomicWrite(string $path, string $content): void {
        $tmp = $path . '.tmp.' . getmypid();
        $error = '';
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $ok = file_put_contents($tmp, $content) !== false && rename($tmp, $path);
            if (!$ok && file_exists($tmp)) {
                unlink($tmp);
            }
        } finally {
            restore_error_handler();
        }

        if (!$ok && !$this->stateWriteFailed) {
            $this->log("Alerts: cannot write {$path}, rule state is kept in memory only: {$error}", LOG_WARNING);
        }
        $this->stateWriteFailed = !$ok;
    }

    private function log(string $message, int $priority): void {
        Debug::getInstance()->log($message, $priority);
    }
}
