<?php

namespace mbolli\nfsen_ng\processor;

/**
 * Provides access to netflow data using a model
 * compatible with nfdump commandline options.
 */
interface Processor {
    /**
     * Sets an option's value.
     *
     * @param null|array|int|string $value
     */
    public function setOption(string $option, $value): void;

    /**
     * Sets a filter's value.
     */
    public function setFilter(string $filter): void;

    /**
     * Executes the processor command, tries to throw an
     * exception based on the return code.
     *
     * @throws \Exception
     */
    public function execute(): array;
}
