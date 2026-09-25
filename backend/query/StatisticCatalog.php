<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\processor\FilterValidator;

/**
 * Every element statistic (`-s <element>`) the Top Talkers picker offers, and the direction
 * families that let one tab switch between any, source and destination.
 */
final class StatisticCatalog {
    /**
     * The five tabs and the element each direction selects. Null: the family has no such
     * direction.
     *
     * @var array<string, array{label: string, any: string, src: ?string, dst: ?string}>
     */
    public const array TABS = [
        'talkers' => ['label' => 'Talkers', 'any' => 'ip', 'src' => 'srcip', 'dst' => 'dstip'],
        'ports' => ['label' => 'Ports', 'any' => 'port', 'src' => 'srcport', 'dst' => 'dstport'],
        'protocols' => ['label' => 'Protocols', 'any' => 'proto', 'src' => null, 'dst' => null],
        'asns' => ['label' => 'ASNs', 'any' => 'as', 'src' => 'srcas', 'dst' => 'dstas'],
        'interfaces' => ['label' => 'Interfaces', 'any' => 'if', 'src' => 'inif', 'dst' => 'outif'],
    ];

    /**
     * Direction triples outside the tabs, keyed by their "any" element.
     *
     * @var array<string, array{any: string, src: string, dst: string}>
     */
    public const array FAMILIES = [
        'tos' => ['any' => 'tos', 'src' => 'srctos', 'dst' => 'dsttos'],
        'mask' => ['any' => 'mask', 'src' => 'srcmask', 'dst' => 'dstmask'],
        'vlan' => ['any' => 'vlan', 'src' => 'srcvlan', 'dst' => 'dstvlan'],
        'natip' => ['any' => 'natip', 'src' => 'natsrcip', 'dst' => 'natdstip'],
        'natport' => ['any' => 'natport', 'src' => 'natsrcport', 'dst' => 'natdstport'],
    ];

    /** @var list<string> */
    public const array DIRECTIONS = ['any', 'src', 'dst'];

    /** @var list<string> the counters a statistic can be ranked by (`-s <element>/<order>`) */
    public const array ORDER_BY = ['flows', 'packets', 'bytes', 'pps', 'bps', 'bpp'];

    /** @var list<string> the NEL elements, which nfdump builds without NEL support reject */
    public const array PROBED = ['nevent', 'nsrcip', 'ndstip', 'nsrcport', 'ndstport'];

    /** @var array<string, array{0: string, 1: string}> element => [label, group], in the picker's order */
    private const array ELEMENTS = [
        'record' => ['Flow Records', 'Flow records'],
        'ip' => ['Any IP address', 'Addresses'],
        'srcip' => ['Src IP address', 'Addresses'],
        'dstip' => ['Dst IP address', 'Addresses'],
        'port' => ['Any port', 'Ports and protocols'],
        'srcport' => ['Src port', 'Ports and protocols'],
        'dstport' => ['Dst port', 'Ports and protocols'],
        'if' => ['Any interface', 'Interfaces'],
        'inif' => ['IN interface', 'Interfaces'],
        'outif' => ['OUT interface', 'Interfaces'],
        'as' => ['Any AS', 'AS'],
        'srcas' => ['Src AS', 'AS'],
        'dstas' => ['Dst AS', 'AS'],
        'nhip' => ['Next Hop IP', 'Addresses'],
        'nhbip' => ['Next Hop BGP IP', 'Addresses'],
        'router' => ['Router IP', 'Addresses'],
        'proto' => ['Proto', 'Ports and protocols'],
        'dir' => ['Direction', 'Interfaces'],
        'srctos' => ['Src TOS', 'ToS'],
        'dsttos' => ['Dst TOS', 'ToS'],
        'tos' => ['Tos', 'ToS'],
        'mask' => ['Any Mask Bits', 'Masks'],
        'srcmask' => ['Src Mask Bits', 'Masks'],
        'dstmask' => ['Dst Mask Bits', 'Masks'],
        'vlan' => ['Any VLAN ID', 'VLAN'],
        'srcvlan' => ['Src VLAN ID', 'VLAN'],
        'dstvlan' => ['Dst VLAN ID', 'VLAN'],
        'srcmac' => ['Src MAC', 'MAC'],
        'dstmac' => ['Dst MAC', 'MAC'],
        'inmac' => ['IN MAC', 'MAC'],
        'outmac' => ['OUT MAC', 'MAC'],
        'insrcmac' => ['IN src MAC', 'MAC'],
        'outdstmac' => ['OUT dst MAC', 'MAC'],
        'indstmac' => ['IN dst MAC', 'MAC'],
        'outsrcmac' => ['OUT src MAC', 'MAC'],
        'mpls1' => ['MPLS Label 1', 'MPLS'],
        'mpls2' => ['MPLS Label 2', 'MPLS'],
        'mpls3' => ['MPLS Label 3', 'MPLS'],
        'mpls4' => ['MPLS Label 4', 'MPLS'],
        'mpls5' => ['MPLS Label 5', 'MPLS'],
        'mpls6' => ['MPLS Label 6', 'MPLS'],
        'mpls7' => ['MPLS Label 7', 'MPLS'],
        'mpls8' => ['MPLS Label 8', 'MPLS'],
        'mpls9' => ['MPLS Label 9', 'MPLS'],
        'mpls10' => ['MPLS Label 10', 'MPLS'],
        'event' => ['NSEL Event type', 'NSEL / Cisco ASA'],
        'xevent' => ['NSEL Extended event', 'NSEL / Cisco ASA'],
        'natsrcip' => ['NSEL NAT Src IP', 'NSEL / Cisco ASA'],
        'natdstip' => ['NSEL NAT Dst IP', 'NSEL / Cisco ASA'],
        'natip' => ['NSEL NAT Src/Dst IP', 'NSEL / Cisco ASA'],
        'natsrcport' => ['NSEL NAT Src Port', 'NSEL / Cisco ASA'],
        'natdstport' => ['NSEL NAT Dst Port', 'NSEL / Cisco ASA'],
        'natport' => ['NSEL NAT Src/Dst Port', 'NSEL / Cisco ASA'],
        'nevent' => ['NAT Event type', 'NEL / NAT'],
        'nsrcip' => ['NAT Src IP', 'NEL / NAT'],
        'ndstip' => ['NAT Dst IP', 'NEL / NAT'],
        'nsrcport' => ['NAT Src Port', 'NEL / NAT'],
        'ndstport' => ['NAT Dst Port', 'NEL / NAT'],
    ];

    /** @var array<string, array<string, string>> binary => element => reason */
    private static array $unsupported = [];

    /**
     * @return list<array{value: string, label: string, group: string}> all 58, in today's order
     */
    public static function all(): array {
        $all = [];
        foreach (self::ELEMENTS as $value => [$label, $group]) {
            $all[] = ['value' => $value, 'label' => $label, 'group' => $group];
        }

        return $all;
    }

    public static function isValid(string $element): bool {
        return isset(self::ELEMENTS[$element]);
    }

    /** The picker label, or the element itself when it is not in the catalog. */
    public static function label(string $element): string {
        return self::ELEMENTS[$element][0] ?? $element;
    }

    /** Tab id for an element or 'more'. */
    public static function tabOf(string $element): string {
        foreach (self::TABS as $tab => $triple) {
            if (\in_array($element, [$triple['any'], $triple['src'], $triple['dst']], true)) {
                return $tab;
            }
        }

        return 'more';
    }

    /** 'any'|'src'|'dst'|null (null: no direction). */
    public static function directionOf(string $element): ?string {
        $triple = self::tripleOf($element);
        if ($triple === null || ($triple['src'] === null && $triple['dst'] === null)) {
            return null;
        }

        $direction = array_search($element, $triple, true);

        return \is_string($direction) && \in_array($direction, self::DIRECTIONS, true) ? $direction : null;
    }

    /**
     * Element for a tab or triple family and a direction; falls back to 'any'. Anything that
     * is neither a tab nor in a family comes back unchanged.
     */
    public static function resolve(string $tabOrElement, string $direction): string {
        $triple = isset(self::TABS[$tabOrElement]) ? self::TABS[$tabOrElement] : self::tripleOf($tabOrElement);
        if ($triple === null) {
            return $tabOrElement;
        }

        $direction = \in_array($direction, self::DIRECTIONS, true) ? $direction : 'any';

        return $triple[$direction] ?? $triple['any'];
    }

    /**
     * NEL elements the running nfdump rejects, probed once per binary with
     * `nfdump -Z '' -s <element>/bytes` (1.7.8: exit 1, "Unknown statistic: nevent").
     *
     * @return array<string, string> element => reason
     */
    public static function unsupported(?string $binary = null): array {
        $binary ??= Config::$settings->nfdumpBinary;
        if (isset(self::$unsupported[$binary])) {
            return self::$unsupported[$binary];
        }

        // A statistic every build knows. When that fails too the binary is missing or broken,
        // which says nothing about the elements, so nothing is marked.
        $control = FilterValidator::check($binary, ['-Z', '', '-s', 'srcip/bytes']);
        if ($control['exitCode'] !== 0) {
            Debug::getInstance()->log('Statistic probe inconclusive: ' . $binary . ' rejected srcip too (exit ' . $control['exitCode'] . ')', LOG_WARNING);

            return self::isTransient($control) ? [] : self::$unsupported[$binary] = [];
        }

        $rejected = [];
        $transient = false;
        foreach (self::PROBED as $element) {
            $run = FilterValidator::check($binary, ['-Z', '', '-s', $element . '/bytes']);
            if ($run['exitCode'] === 0) {
                continue;
            }
            if (self::isTransient($run)) {
                $transient = true;

                continue;
            }

            $reason = trim(explode("\n", trim($run['stderr']))[0]);
            $rejected[$element] = $reason !== '' ? $reason : 'Not supported by this nfdump (exit ' . $run['exitCode'] . ')';
        }

        return $transient ? $rejected : self::$unsupported[$binary] = $rejected;
    }

    /**
     * A timeout or a failed start says nothing about the binary, so the answer is not cached.
     *
     * @param array{exitCode: int, timedOut: bool} $run
     */
    private static function isTransient(array $run): bool {
        return $run['timedOut'] || $run['exitCode'] === -1;
    }

    /**
     * The tab or family triple an element belongs to.
     *
     * @return null|array{any: string, src: ?string, dst: ?string}
     */
    private static function tripleOf(string $element): ?array {
        foreach ([...array_values(self::TABS), ...array_values(self::FAMILIES)] as $triple) {
            if (\in_array($element, [$triple['any'], $triple['src'], $triple['dst']], true)) {
                return ['any' => $triple['any'], 'src' => $triple['src'], 'dst' => $triple['dst']];
            }
        }

        return null;
    }
}
