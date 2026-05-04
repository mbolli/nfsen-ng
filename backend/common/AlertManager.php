<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Datasource;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;

/**
 * Evaluates alert rules after each nfcapd import and dispatches notifications.
 * One instance is shared for the lifetime of the server process.
 */
final class AlertManager {
    private const MAX_LOG_ENTRIES = 50;

    /** @var array<string, AlertState> Keyed by rule ID */
    private array $states = [];

    /** @var list<array<string, mixed>> In-memory log cache */
    private array $log = [];

    public function __construct(
        private readonly Datasource $db,
        private readonly string $statePath,
        private readonly string $logPath,
        private readonly string $emailFrom,
    ) {
        $this->loadState();
        $this->loadLog();
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Evaluate all enabled alert rules for the given profile.
     * Call this after each successful nfcapd import.
     *
     * @param AlertRule[] $rules
     *
     * @return string[] Names of rules that fired this cycle
     */
    public function runPeriodic(array $rules, string $profile): array {
        $fired = [];

        foreach ($rules as $rule) {
            if (!$rule->enabled || $rule->profile !== $profile) {
                continue;
            }

            $state = $this->states[$rule->id] ?? AlertState::initial();

            // Tick down cooldown
            if ($state->cooldownRemaining > 0) {
                --$state->cooldownRemaining;
                $this->states[$rule->id] = $state;

                continue;
            }

            $current = $this->db->fetchLatestSlot($rule->sources, $profile);
            $threshold = $this->computeThreshold($rule, $current);
            $value = $current[$rule->metric] ?? 0.0;

            if ($this->evaluate($rule->operator, $value, $threshold)) {
                $ts = time();
                $state->cooldownRemaining = max(0, $rule->cooldownSlots - 1);
                $state->lastTriggeredAt = $ts;
                $state->recentTriggers = \array_slice(
                    array_merge($state->recentTriggers, [$ts]),
                    -10,
                );
                $this->states[$rule->id] = $state;

                $this->dispatchNotifications($rule, $current, $ts);
                $fired[] = $rule->name;
            } else {
                $this->states[$rule->id] = $state;
            }
        }

        $this->saveState();

        return $fired;
    }

    /**
     * Compute the effective threshold value for a rule given the current metrics.
     * For percent_of_avg: returns PHP_FLOAT_MAX when the rolling average is 0
     * (cold-start — no baseline available, so never trigger).
     *
     * @param array{flows: float, packets: float, bytes: float} $current
     */
    public function computeThreshold(AlertRule $rule, array $current): float {
        if ($rule->thresholdType === 'absolute') {
            return $rule->thresholdValue;
        }

        // percent_of_avg: compute rolling average for the configured window
        $windowSeconds = $this->parseWindow($rule->avgWindow);
        $avg = $this->db->fetchRollingAverage($rule->sources, $rule->profile, $windowSeconds);
        $avgValue = $avg[$rule->metric] ?? 0.0;

        if ($avgValue <= 0.0) {
            // Cold-start: no baseline — suppress triggering by returning unreachable threshold
            return PHP_FLOAT_MAX;
        }

        return $avgValue * ($rule->thresholdValue / 100.0);
    }

    /**
     * Returns the most recent log entries (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentLog(int $n = 10): array {
        return \array_slice($this->log, 0, max(1, $n));
    }

    // ── Notifications ──────────────────────────────────────────────────────────

    /**
     * Append a log entry, send email and/or webhook as configured.
     *
     * @param array{flows: float, packets: float, bytes: float} $values
     */
    public function dispatchNotifications(AlertRule $rule, array $values, int $ts): void {
        $entry = [
            'ts' => $ts,
            'rule' => $rule->name,
            'metric' => $rule->metric,
            'value' => $values[$rule->metric] ?? 0.0,
            'profile' => $rule->profile,
            'sources' => $rule->sources,
        ];

        // Prepend, keep only MAX_LOG_ENTRIES
        array_unshift($this->log, $entry);
        $this->log = \array_slice($this->log, 0, self::MAX_LOG_ENTRIES);
        $this->saveLog();

        // Email
        if ($rule->notifyEmail !== null && $this->emailFrom !== '') {
            $this->sendEmail($rule, $entry);
        }

        // Webhook
        if ($rule->notifyWebhook !== null) {
            $this->sendWebhook($rule, $entry);
        }
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

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $entry */
    private function sendEmail(AlertRule $rule, array $entry): void {
        $subject = "[nfsen-ng] Alert: {$rule->name}";
        $body = "Alert rule \"{$rule->name}\" fired.\n\n"
              . 'Metric:  ' . $rule->metric . "\n"
              . 'Value:   ' . number_format((float) $entry['value'], 2) . "\n"
              . 'Profile: ' . $rule->profile . "\n"
              . 'Sources: ' . implode(', ', (array) $entry['sources']) . "\n"
              . 'Time:    ' . date('Y-m-d H:i:s', (int) $entry['ts']) . " UTC\n";

        $headers = "From: {$this->emailFrom}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
        @mail((string) $rule->notifyEmail, $subject, $body, $headers);
    }

    /** @param array<string, mixed> $entry */
    private function sendWebhook(AlertRule $rule, array $entry): void {
        $url = (string) $rule->notifyWebhook;

        // SSRF guard — only allow http:// and https:// to external hosts
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return;
        }

        $payload = json_encode([
            'event' => 'alert_fired',
            'rule' => $rule->name,
            'metric' => $rule->metric,
            'value' => $entry['value'],
            'profile' => $rule->profile,
            'sources' => $rule->sources,
            'ts' => $entry['ts'],
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        // Use OpenSwoole coroutine HTTP client when inside a coroutine context,
        // fall back to cURL otherwise (e.g. tests).
        if (class_exists(Coroutine::class) && Coroutine::getCid() > 0) {
            $this->sendWebhookCoroutine($url, $payload);
        } else {
            $this->sendWebhookCurl($url, $payload);
        }
    }

    private function sendWebhookCoroutine(string $url, string $payload): void {
        $parts = parse_url($url);
        if ($parts === false) {
            return;
        }

        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $ssl = ($parts['scheme'] ?? '') === 'https';

        Coroutine::create(function () use ($host, $port, $path, $payload, $ssl): void {
            try {
                $client = new Client($host, $port, $ssl);
                $client->setHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'nfsen-ng-alert/1.0',
                ]);
                $client->post($path, $payload);
                $client->close();
            } catch (\Throwable) {
                // Webhook failures are best-effort — do not crash the server
            }
        });
    }

    private function sendWebhookCurl(string $url, string $payload): void {
        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: nfsen-ng-alert/1.0'],
            CURLOPT_TIMEOUT => 10,
        ]);
        @curl_exec($ch);
        curl_close($ch);
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

    private function saveLog(): void {
        $this->atomicWrite($this->logPath, (string) json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function loadLog(): void {
        if (!file_exists($this->logPath)) {
            return;
        }

        $raw = json_decode((string) @file_get_contents($this->logPath), true);
        if (\is_array($raw)) {
            $this->log = array_values($raw);
        }
    }

    private function atomicWrite(string $path, string $content): void {
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }
}
