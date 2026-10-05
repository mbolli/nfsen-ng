<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

/**
 * pdo_sqlite is missing, the state directory is not writable, or opening or migrating
 * failed. Consumers degrade to an "unavailable" state and show {@see $reason}.
 */
final class StoreUnavailableException extends \RuntimeException {
    public function __construct(
        public readonly string $reason,
        public readonly string $path = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($path === '' ? $reason : "SQLite store {$path} is unavailable: {$reason}", 0, $previous);
    }
}
