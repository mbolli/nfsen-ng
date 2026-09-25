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
 *     notes?: list<string>,
 *     exitCode?: int,
 * }
 */
interface Processor {
    /**
     * Sets an option's value. A list emits the option once per element.
     *
     * @param null|array<mixed>|int|string $value
     */
    public function setOption(string $option, $value): void;

    /**
     * Sets a filter's value.
     */
    public function setFilter(string $filter): void;

    /**
     * Names this processor's runs so a concurrent caller can kill its own query rather than
     * whichever one started last. Implementations that cannot run concurrently may ignore it.
     */
    public function setQueryHandle(string $handle): void;

    /**
     * Override the nfdump profile used for path construction.
     * Must be called before setOption('-M', ...) to take effect.
     */
    public function setProfile(string $profile): void;

    /**
     * Executes the processor command. `command` is plain text, `notes` what the tool printed
     * beside the data, `exitCode` its exit code.
     *
     * @return ProcessorResult
     *
     * @throws \Exception
     */
    public function execute(): array;
}
