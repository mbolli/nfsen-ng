<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

/**
 * A saved filter with the same normalised expression exists already.
 */
final class DuplicateFilterException extends \RuntimeException {
    public function __construct(
        public readonly int $existingId,
        public readonly string $existingName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Already saved as ' . $existingName, 0, $previous);
    }
}
