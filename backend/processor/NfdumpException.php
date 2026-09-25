<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

/**
 * nfdump ran and failed. The message is plain text that can quote the filter verbatim, markup
 * included, so render it escaped; command, stderr and exit code travel separately.
 */
final class NfdumpException extends \RuntimeException {
    public function __construct(
        string $message,
        public readonly string $command = '',
        public readonly string $stderr = '',
        public readonly int $exitCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $previous);
    }

    /** nfdump never exits 9 or 15 itself: the run was killed, usually by the Kill button. */
    public function wasStopped(): bool {
        return $this->exitCode === 9 || $this->exitCode === 15;
    }
}
