<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages\state;

use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\QueryResult;
use Mbolli\PhpVia\Context;

/**
 * Plain-PHP results a page keeps between an action and the view: its notifications, and
 * which large result blocks the client already holds (D26).
 *
 * @phpstan-type Notification array{id: string, type: 'error'|'info'|'success'|'warning', message: string, code: string}
 */
abstract class PageState {
    public const int MAX_NOTIFICATIONS = 5;

    /**
     * Newest first. message is plain text and code a verbatim string (a command, tool
     * output); templates escape both (D21).
     *
     * @var list<Notification>
     */
    public array $notifications = [];

    private bool $wasActive = false;

    /** @var array<string, string> slot => result id the client received in full */
    private array $sentResults = [];

    /** @var array<string, string> slot => result id the render being built carries */
    private array $pendingResults = [];

    public function notify(string $type, string $message, string $code = ''): void {
        array_unshift($this->notifications, [
            'id' => bin2hex(random_bytes(4)),
            'type' => self::type($type),
            'message' => $message,
            'code' => $code,
        ]);
        $this->notifications = \array_slice($this->notifications, 0, self::MAX_NOTIFICATIONS);
    }

    public function dismiss(string $id): void {
        $this->notifications = array_values(array_filter(
            $this->notifications,
            static fn (array $n): bool => $n['id'] !== $id,
        ));
    }

    public function clearNotifications(): void {
        $this->notifications = [];
    }

    /** Replaces the notices with the outcome of a finished query run and the command that ran. */
    public function notifyResult(QueryResult $result, float $elapsed, string $what): void {
        $this->clearNotifications();
        if ($result->stderr !== '') {
            $this->notify('warning', 'nfdump warning:', $result->stderr);
        }
        if ($result->command === '') {
            $this->notify('success', "{$what} processed in {$elapsed}s.");

            return;
        }
        $this->notify('success', "nfdump: done in {$elapsed}s.", $result->command);
    }

    /**
     * Replaces the notices with a failure. nfdump's message can quote the filter verbatim,
     * markup included, so it stays plain text beside the command that failed (D21).
     */
    public function notifyFailure(\Throwable $e): void {
        $this->clearNotifications();
        $this->notify('error', 'Error: ' . $e->getMessage(), $e instanceof NfdumpException ? $e->command : '');
    }

    /** Called by Shell::render() for every page state once the render data is built (D26). */
    public function markRendered(bool $active): void {
        $this->wasActive = $active;
        $this->sentResults = $active ? $this->pendingResults : [];
        $this->pendingResults = [];
    }

    /**
     * D26: whether host `$slot` carries the full result: on the initial GET, when the page was
     * inactive in the previous render, or when $resultId differs from what that slot last sent.
     */
    public function sendResult(string $slot, string $resultId, bool $isUpdate): bool {
        $send = !$isUpdate || !$this->wasActive || ($this->sentResults[$slot] ?? null) !== $resultId;
        $this->pendingResults[$slot] = $resultId;

        return $send;
    }

    /** A fresh id for a stored result, so a rerun with identical inputs still replaces the host. */
    public static function newResultId(): string {
        return bin2hex(random_bytes(4));
    }

    /** Pushes restored values that live in signals back into the context (revival only). */
    public function restoreSignals(Context $c): void {}

    /** @return array<string, mixed> */
    abstract public function snapshot(): array;

    /** @param array<string, mixed> $data */
    abstract public function restore(array $data): void;

    abstract public function isEmpty(): bool;

    /**
     * Snapshot data comes back from app-global state, so it is re-validated on the way in.
     *
     * @return list<Notification>
     */
    protected static function notificationsFrom(mixed $value): array {
        $list = [];
        foreach (\is_array($value) ? $value : [] as $n) {
            if (!\is_array($n) || !\is_string($n['id'] ?? null) || !\is_string($n['message'] ?? null)) {
                continue;
            }
            $list[] = [
                'id' => $n['id'],
                'type' => self::type($n['type'] ?? ''),
                'message' => $n['message'],
                'code' => \is_string($n['code'] ?? null) ? $n['code'] : '',
            ];
        }

        return \array_slice($list, 0, self::MAX_NOTIFICATIONS);
    }

    protected static function stringFrom(mixed $value): string {
        return \is_string($value) ? $value : '';
    }

    /** @return 'error'|'info'|'success'|'warning' */
    private static function type(mixed $type): string {
        return match ($type) {
            'success' => 'success',
            'warning' => 'warning',
            'error' => 'error',
            default => 'info',
        };
    }
}
