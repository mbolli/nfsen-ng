<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp;

/**
 * What a tool costs to answer, which decides the order an agent should reach for them.
 *
 * The datasource holds five-minute aggregates and answers in milliseconds. nfdump answers
 * the same question about *who* and *what* by reading every capture file in the window. A
 * caller that does not know the difference will scan hours of captures to learn something
 * the stored series already knew, so every tool states its tier in its own description.
 */
enum Tier: string {
    case Cheap = 'cheap';
    case Expensive = 'expensive';

    public function note(): string {
        return match ($this) {
            self::Cheap => 'Cost: cheap. Answered from stored aggregates without reading capture files. '
                . 'Use these first to establish when something happened and how large it is.',
            self::Expensive => 'Cost: expensive. Reads capture files with nfdump, which scales with the time '
                . 'window. Establish the window with a cheap tool first, and price it with estimate_cost.',
        };
    }
}
