<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

/**
 * Provides access to netflow data using a model
 * compatible with nfdump commandline options.
 *
 * @phpstan-type ProcessorResult array{
 *     command: string,
 *     rawOutput: string,
 *     decoded: array<mixed>,
 *     stderr?: string,
 * }
 */
interface Processor {
    /**
     * Sets an option's value.
     *
     * @param null|array<mixed>|int|string $value
     */
    public function setOption(string $option, $value): void;

    /**
     * Sets a filter's value.
     */
    public function setFilter(string $filter): void;

    /**
     * Override the nfdump profile used for path construction.
     * Must be called before setOption('-M', ...) to take effect.
     */
    public function setProfile(string $profile): void;

    /**
     * Executes the processor command, tries to throw an
     * exception based on the return code.
     *
     * @return ProcessorResult
     *
     * @throws \Exception
     */
    public function execute(): array;
}
