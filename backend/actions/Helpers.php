<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use Mbolli\PhpVia\Context;

/**
 * Shared static helpers used by multiple action classes.
 */
final class Helpers {
    /**
     * The aggregation signals of one panel, as Nfdump::buildAggregationString() wants them.
     *
     * The Flows panel and the Statistics panel each own a set of these, under their own
     * prefix, so the same eight controls can be read without either tab reaching into the
     * other's state. A signal the context does not have contributes its "off" value, which
     * keeps a panel that has not declared the whole set from aggregating by accident.
     *
     * @return array<string, mixed>
     */
    public static function aggregationFromSignals(Context $c, string $prefix): array {
        $flag = static fn (string $name): bool => $c->getSignal($prefix . $name)?->bool() ?? false;
        $text = static fn (string $name, string $default): string => $c->getSignal($prefix . $name)?->string() ?? $default;

        return [
            'bidirectional' => $flag('bidirectional'),
            'proto' => $flag('proto'),
            'srcport' => $flag('srcport'),
            'dstport' => $flag('dstport'),
            'srcip' => $text('srcip', 'none'),
            'srcipPrefix' => $text('srcip_prefix', ''),
            'dstip' => $text('dstip', 'none'),
            'dstipPrefix' => $text('dstip_prefix', ''),
        ];
    }

    /**
     * Resolve the sources selected in the UI (the graph_sources signal) to the
     * concrete list of sources to pass to nfdump's -M option.
     *
     * An empty selection or the special "any" sentinel means "all configured
     * sources"; otherwise the user's explicit selection is honoured verbatim.
     *
     * The signal is client-writable: entries arrive as arbitrary scalars under arbitrary
     * keys and are normalised before they reach nfdump's -M option.
     *
     * @param array<int|string, mixed> $selected the graph_sources signal value
     *
     * @return list<string>
     */
    public static function resolveSources(array $selected): array {
        $sources = array_values(array_filter(
            array_map(static fn (mixed $s): string => \is_scalar($s) ? trim((string) $s) : '', $selected),
            static fn (string $s): bool => $s !== ''
        ));

        if ($sources === [] || \in_array('any', $sources, true)) {
            return Config::$settings->sources;
        }

        return $sources;
    }
}
