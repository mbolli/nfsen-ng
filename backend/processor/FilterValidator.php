<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;

/**
 * Checks a filter with `nfdump -Z` (parse only, 1 to 4 ms). It bypasses Nfdump::execute(),
 * so a check takes no nfdump slot and never waits behind a running query.
 */
final class FilterValidator {
    public const float TIMEOUT_SECONDS = 2.0;
    public const int CACHE_SIZE = 128;
    public const string UNCHECKED = 'Filter could not be checked';
    private const string TIMEOUT_BINARY = '/usr/bin/timeout';

    /** @var array<string, array{valid: bool, message: string}> binary and filter => answer, oldest first */
    private static array $cache = [];

    /**
     * `valid` is exit code 0. `message` is nfdump's `Line N:` text (no `Line 1: ` for a one-line
     * filter) or first stderr line, verbatim: it can quote the filter's markup, so escape it.
     *
     * @return array{valid: bool, message: string}
     */
    public static function validate(string $filter, ?string $binary = null): array {
        if (trim($filter) === '') {
            return ['valid' => true, 'message' => ''];
        }
        if (str_contains($filter, "\0")) {
            return ['valid' => false, 'message' => 'The filter contains a NUL character.'];
        }

        $binary ??= Config::$settings->nfdumpBinary;
        $key = $binary . "\0" . $filter;
        if (isset(self::$cache[$key])) {
            $answer = self::$cache[$key];
            unset(self::$cache[$key]);

            return self::$cache[$key] = $answer;
        }

        // `--` keeps a filter that starts with a dash from being read as an option.
        $run = self::check($binary, ['-Z', '--', $filter]);
        // With the exit code unknown, nfdump's `Line N:` error text still marks a rejected filter.
        $valid = $run['exitCode'] === 0 && ($run['exitKnown'] || preg_match('/^Line \d+:/m', $run['stdout']) !== 1);
        $answer = [
            'valid' => $valid,
            'message' => $valid ? '' : self::message($run['stdout'], $run['stderr'], !str_contains(trim($filter), "\n")),
        ];

        // A timeout (a slow DNS lookup), a failed start or a guessed exit code is asked again.
        if (!$run['timedOut'] && $run['exitCode'] !== -1 && $run['exitKnown']) {
            self::$cache[$key] = $answer;
            if (\count(self::$cache) > self::CACHE_SIZE) {
                unset(self::$cache[array_key_first(self::$cache)]);
            }
        }

        return $answer;
    }

    /**
     * Runs the binary with $args, without a shell, under the time limit when
     * /usr/bin/timeout exists. Reads both outputs fully, so it is for small answers only.
     *
     * @param list<string> $args
     *
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, exitKnown: bool}
     */
    public static function check(string $binary, array $args): array {
        $limited = is_executable(self::TIMEOUT_BINARY);
        $argv = $limited
            ? [self::TIMEOUT_BINARY, (string) self::TIMEOUT_SECONDS, $binary, ...$args]
            : [$binary, ...$args];

        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return ['exitCode' => -1, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'exitKnown' => false];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $polled = self::polledExitCode($process);
        $exitCode = $polled ?? Nfdump::exitCodeFrom(proc_close($process));
        if ($polled !== null) {
            proc_close($process);
        }

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            // 124 is timeout's own code for "the command ran out of time".
            'timedOut' => $limited && $exitCode === 124,
            'exitKnown' => $polled !== null,
        ];
    }

    /**
     * Under OpenSwoole's process hook proc_close() sometimes reports 0 for a failed run, so the
     * exit code is read with proc_get_status() first. Null when it could not be seen.
     *
     * @param resource $process
     */
    private static function polledExitCode($process): ?int {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS + 1.0;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status['exitcode'] >= 0 ? $status['exitcode'] : null;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /** The protocol table nfdump prints after an unknown protocol is never reached. */
    private static function message(string $stdout, string $stderr, bool $singleLine): string {
        foreach (explode("\n", $stdout) as $line) {
            if (preg_match('/^Line \d+: /', $line) === 1) {
                $line = trim($line);

                return $singleLine ? (string) preg_replace('/^Line 1: /', '', $line) : $line;
            }
        }

        foreach (explode("\n", $stderr) as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return self::UNCHECKED;
    }
}
