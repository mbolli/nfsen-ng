<?php

namespace nfsen_ng\processor;

/**
 * Provides access to netflow data using a model
 * compatible with nfdump commandline options.
 */
interface processor {
    /**
     * Sets an option's value.
     */
    public function setOption($option, $value);

    /**
     * Sets a filter's value.
     */
    public function setFilter($filter);

    /**
     * Executes the processor command, tries to throw an
     * exception based on the return code.
     *
     * @return array
     *
     * @throws \Exception
     */
    public function execute();
}
