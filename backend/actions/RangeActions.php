<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\RangeControls;
use Mbolli\PhpVia\Context;

/**
 * The global window, sources, protocol, unit and profile (4.0.2): set-range, apply-globals and
 * change-profile. None of them reads a capture file; the render that follows reads stored series.
 *
 * @phpstan-type RangeState array{datestart: int, dateend: int, range_live: bool, range_preset: string}
 */
final class RangeActions {
    /** Absolute ranges snap to the capture interval, and no window is narrower than one. */
    public const int STEP = 300;

    /** A window ending closer to now than this is live. */
    public const int LIVE_SLACK = 300;

    /**
     * A profile whose newest sample is younger than this is current: a sample is stamped with
     * its interval's start and stored once the interval closes, so current data is 300 to 600 s old.
     */
    public const int FRESH_DATA = 3 * self::STEP;

    /** Seconds per custom duration unit. */
    public const array UNITS = ['h' => 3600, 'd' => 86400, 'w' => 604800];

    public const int MAX_DURATION = 9999;

    /** Leads every banner set-range raises, so the next op that succeeds can clear it. */
    private const string ERROR_PREFIX = 'Range: ';

    public static function register(Context $c, PageStates $states): void {
        $c->action(static function (Context $c) use ($states): void {
            $error = $c->getSignal('_error');

            try {
                $op = $c->input('op');
                $next = self::compute(
                    \is_string($op) ? $op : '',
                    self::inputs($c),
                    self::state($c),
                    time(),
                    $c->getSignal('data_range_min')?->int() ?? 0,
                );
                self::apply($c, $next);
                $states->shell->graphFetchedAt = 0;
                if (str_starts_with($error?->string() ?? '', self::ERROR_PREFIX)) {
                    $error?->setValue('');
                }
            } catch (\InvalidArgumentException $e) {
                $error?->setValue(self::ERROR_PREFIX . $e->getMessage());
            } catch (\Throwable $e) {
                Debug::getInstance()->log('set-range failed: ' . $e->getMessage(), LOG_ERR);
                $error?->setValue(self::ERROR_PREFIX . 'the change failed: ' . $e->getMessage());
            }
            $c->sync();
        }, 'set-range');

        $c->action(static function (Context $c) use ($states): void {
            try {
                self::applyGlobals($c);
                $states->shell->graphFetchedAt = 0;
            } catch (\Throwable $e) {
                Debug::getInstance()->log('apply-globals failed: ' . $e->getMessage(), LOG_ERR);
                $c->getSignal('_error')?->setValue('Could not apply the selection: ' . $e->getMessage());
            }
            $c->sync();
        }, 'apply-globals');

        $c->action(static function (Context $c) use ($states): void {
            try {
                $now = time();
                if (!self::changeProfile($c, $now)) {
                    return;
                }
                $states->shell->rangeFetchedAt = $now;
                $states->shell->graphFetchedAt = 0;
            } catch (\Throwable $e) {
                Debug::getInstance()->log('change-profile failed: ' . $e->getMessage(), LOG_ERR);
                $c->getSignal('_error')?->setValue('Could not switch the profile: ' . $e->getMessage());
            }
            $c->sync();
        }, 'change-profile');
    }

    /**
     * The window after one set-range op (all times epoch seconds). Pure: `$state` is the current
     * window, `$min` the start of the data. A live window is taken to end now, since only renders
     * of an analysis page move it along.
     *
     * @param array<string, mixed> $input the op's query parameters
     * @param RangeState           $state
     *
     * @return RangeState
     *
     * @throws \InvalidArgumentException for input that names no valid window; the message is shown as is
     */
    public static function compute(string $op, array $input, array $state, int $now, int $min): array {
        $min = min(max(0, $min), $now - self::STEP);
        $width = max(self::STEP, $state['dateend'] - $state['datestart']);
        [$start, $end] = $state['range_live'] ? [$now - $width, $now] : [$state['datestart'], $state['dateend']];
        $preset = $state['range_preset'];

        switch ($op) {
            case 'preset':
                $id = \is_string($input['v'] ?? null) ? $input['v'] : '';
                if (!isset(RangeControls::PRESETS[$id])) {
                    throw new \InvalidArgumentException('Unknown range preset.');
                }

                return self::window($now - RangeControls::PRESETS[$id], $now, true, $id);

            case 'duration':
                $n = self::int($input['n'] ?? null);
                $unit = \is_string($input['u'] ?? null) ? $input['u'] : '';
                if ($n === null || $n < 1 || $n > self::MAX_DURATION) {
                    throw new \InvalidArgumentException('Enter a duration between 1 and ' . self::MAX_DURATION . '.');
                }
                if (!isset(self::UNITS[$unit])) {
                    throw new \InvalidArgumentException('Unknown duration unit.');
                }

                return self::window(max(0, $now - $n * self::UNITS[$unit]), $now, true, 'custom');

            case 'abs':
                return self::absolute(self::int($input['from'] ?? null), self::int($input['to'] ?? null), $now, $min);

            case 'back':
                if ($start <= $min) {
                    throw new \InvalidArgumentException('Already at the start of the stored data.');
                }
                $from = max($min, $start - $width);

                return self::window($from, min($now, $from + $width), false, self::keepPreset($preset, $width));

            case 'forward':
                $to = self::snapToNow(min($end + $width, $now), $now);

                return self::window(max($min, $to - $width), $to, $to === $now, self::keepPreset($preset, $width));

            case 'now':
                return self::window($now - $width, $now, true, self::keepPreset($preset, $width));

            case 'zoomout':
                $wider = min(2 * $width, $now - $min);
                $to = self::snapToNow(max($min, min(intdiv($start + $end, 2) - intdiv($wider, 2), $now - $wider)) + $wider, $now);

                return self::window(max($min, $to - $wider), $to, $to === $now, 'custom');

            case 'pin':
                return self::window($start, $end, false, $preset);

            default:
                throw new \InvalidArgumentException('Unknown range operation.');
        }
    }

    /**
     * The global sources a client posted, cut down to configured ones in their configured
     * order; nothing, or the 'any' sentinel, means all of them.
     *
     * @param list<string> $configured
     *
     * @return list<string>
     */
    public static function normalizeSources(mixed $sources, array $configured): array {
        $picked = \is_array($sources)
            ? array_map(static fn (mixed $s): string => \is_scalar($s) ? trim((string) $s) : '', $sources)
            : [];
        if ($picked === [] || \in_array('any', $picked, true)) {
            return $configured;
        }
        $kept = array_values(array_filter($configured, static fn (string $s): bool => \in_array($s, $picked, true)));

        return $kept === [] ? $configured : $kept;
    }

    /** Normalises graph_sources, protocol and graph_trafficUnit to what they may hold. */
    public static function applyGlobals(Context $c): void {
        $sources = $c->getSignal('graph_sources');
        if ($sources !== null) {
            $normalized = self::normalizeSources($sources->getValue(), Config::$settings->sources);
            if ($sources->getValue() !== $normalized) {
                $sources->setValue($normalized);
            }
        }

        $protocol = $c->getSignal('protocol');
        if ($protocol !== null) {
            $normalized = RangeControls::normalizeProtocol($protocol->getValue());
            if ($protocol->getValue() !== $normalized) {
                $protocol->setValue($normalized);
            }
        }

        $unit = $c->getSignal('graph_trafficUnit');
        if ($unit !== null) {
            $normalized = Settings::normalizeUnit($unit->getValue());
            if ($unit->getValue() !== $normalized) {
                $unit->setValue($normalized);
            }
        }
    }

    /**
     * Remembers the profile and slides the window, keeping its width, to the end of the new
     * profile's data: live when that data is current. False, and nothing changed, for a
     * profile that does not exist.
     */
    public static function changeProfile(Context $c, int $now): bool {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $dataRangeMax = $c->getSignal('data_range_max');
        $selectedProfile = $c->getSignal('selected_profile');
        $rangeLive = $c->getSignal('range_live');
        \assert($datestart !== null && $dateend !== null && $dataRangeMax !== null && $selectedProfile !== null);

        $profile = $selectedProfile->string();
        if (!\in_array($profile, Config::detectProfiles(), true)) {
            return false;
        }

        SettingsActions::exclusively(static function () use ($profile): void {
            $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);
            $prefs->withSelectedProfile($profile)->save(Config::$prefsFile);
        });

        GraphActions::updateDataRange($c);

        $width = max(self::STEP, $dateend->int() - $datestart->int());
        $last = $dataRangeMax->int();
        $live = $now - $last < self::FRESH_DATA;
        $end = $live ? $now : $last;
        $dateend->setValue($end);
        $datestart->setValue($end - $width);
        $rangeLive?->setValue($live);

        return true;
    }

    /**
     * @return RangeState
     *
     * @throws \InvalidArgumentException
     */
    private static function absolute(?int $from, ?int $to, int $now, int $min): array {
        if ($from === null || $to === null) {
            throw new \InvalidArgumentException('Enter a start and an end for the range.');
        }
        if ($from >= $to) {
            throw new \InvalidArgumentException('The range has to end after it starts.');
        }

        $from = max($from, $min);
        $to = min($to, $now);
        if ($from >= $to) {
            throw new \InvalidArgumentException('The range lies outside the stored data.');
        }

        $from = intdiv($from, self::STEP) * self::STEP;
        $to = self::snapToNow((int) (ceil($to / self::STEP) * self::STEP), $now);

        return self::window(min($from, $to - self::STEP), $to, $to === $now, 'custom');
    }

    /** An end this close to now is now: the window is live, whatever the seconds since. */
    private static function snapToNow(int $to, int $now): int {
        return $now - $to < self::LIVE_SLACK ? $now : $to;
    }

    /** @return RangeState */
    private static function window(int $from, int $to, bool $live, string $preset): array {
        return ['datestart' => $from, 'dateend' => $to, 'range_live' => $live, 'range_preset' => $preset];
    }

    /** The preset still names the window only while the width is the preset's. */
    private static function keepPreset(string $preset, int $width): string {
        return (RangeControls::PRESETS[$preset] ?? null) === $width ? $preset : 'custom';
    }

    private static function int(mixed $value): ?int {
        if (\is_int($value)) {
            return $value;
        }
        $parsed = \is_string($value) ? filter_var(trim($value), FILTER_VALIDATE_INT) : false;

        return $parsed === false ? null : $parsed;
    }

    /** @return array<string, mixed> */
    private static function inputs(Context $c): array {
        $input = [];
        foreach (['v', 'n', 'u', 'from', 'to'] as $key) {
            $input[$key] = $c->input($key);
        }

        return $input;
    }

    /** @return RangeState */
    private static function state(Context $c): array {
        return [
            'datestart' => $c->getSignal('datestart')?->int() ?? 0,
            'dateend' => $c->getSignal('dateend')?->int() ?? 0,
            'range_live' => $c->getSignal('range_live')?->bool() ?? false,
            'range_preset' => $c->getSignal('range_preset')?->string() ?? 'custom',
        ];
    }

    /** @param RangeState $next */
    private static function apply(Context $c, array $next): void {
        foreach ($next as $name => $value) {
            $signal = $c->getSignal($name);
            if ($signal !== null && $signal->getValue() !== $value) {
                $signal->setValue($value);
            }
        }
    }
}
