<?php

declare(strict_types=1);

use mbolli\nfsen_ng\processor\FilterValidator;
use mbolli\nfsen_ng\query\FilterGrammar;
use OpenSwoole\Coroutine;

/** A binary operator snippet is checked after a term, as the editor inserts it. */
function filterGrammarCheckable(string $snippet): string {
    $filled = FilterGrammar::fill($snippet);

    return preg_match('/^(and|or)\b/', $filled) === 1 ? 'proto tcp ' . $filled : $filled;
}

describe('FilterGrammar', function (): void {
    $real = '/usr/local/nfdump/bin/nfdump';

    test('offers the Basic and Advanced primitives of 4.5.3', function (): void {
        $snippets = static fn (string $group): array => array_column(
            array_filter(FilterGrammar::fields(), static fn (array $f): bool => $f['group'] === $group),
            'snippet',
        );

        expect($snippets('basic'))->toContain(
            'src host <ip>',
            'dst host <ip>',
            'host <ip>',
            'src net <cidr>',
            'dst net <cidr>',
            'net <cidr>',
            'src port <n>',
            'dst port <n>',
            'port <n>',
            'port in [<a> <b>]',
            'proto <protocol>',
            'bytes > <n>',
            'packets > <n>',
            'flags <flags>',
            'not <expr>',
            'and <expr>',
            'or <expr>',
            '( <expr> )',
        )
            ->and($snippets('advanced'))->toContain(
                'src as <asn>',
                'dst as <asn>',
                'as <asn>',
                'in if <n>',
                'out if <n>',
                'vlan <n>',
                'tos <n>',
                'next ip <ip>',
                'router ip <ip>',
                'ipv4',
                'ipv6',
                'duration > <ms>',
                'bps > <n>',
                'pps > <n>',
                'bpp > <n>',
                'src mac <mac>',
                'mpls label1 <n>',
            )
            ->and(count($snippets('basic')) + count($snippets('advanced')))->toBe(count(FilterGrammar::fields()))
        ;
    });

    test('every field has a label and help, and every placeholder a sample value', function (): void {
        foreach (FilterGrammar::fields() as $field) {
            expect($field['label'])->not->toBe('')
                ->and($field['help'])->not->toBe('')
                ->and(FilterGrammar::fill($field['snippet']))->not->toMatch('/<[a-z]+>/')
            ;
        }
    });

    test('has the eight examples, each described', function (): void {
        $examples = FilterGrammar::examples();

        expect($examples)->toHaveCount(8)
            ->and(array_column($examples, 'expression'))->toBe([
                'dst port in [80 443]',
                'port 53',
                'src net 10.0.0.0/8 and dst net 10.0.0.0/8',
                'bytes > 100M',
                'proto tcp and flags S and not flags A',
                'proto icmp or proto icmp6',
                'host 192.0.2.10',
                'not net 192.168.0.0/16',
            ])
            ->and(array_filter(array_column($examples, 'description'), static fn (string $d): bool => $d === ''))->toBe([])
        ;
    });

    test('autocomplete knows every word the snippets spell out, sorted and unique', function (): void {
        $keywords = FilterGrammar::keywords();
        $sorted = $keywords;
        sort($sorted);

        foreach (FilterGrammar::fields() as $field) {
            $words = preg_split('/[^a-z0-9-]+/', (string) preg_replace('/<[a-z]+>/', ' ', $field['snippet']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_filter($words, static fn (string $w): bool => preg_match('/^[a-z]/', $w) === 1) as $word) {
                expect($keywords)->toContain($word);
            }
        }
        expect($keywords)->toBe($sorted)
            ->and($keywords)->toBe(array_values(array_unique($keywords)))
            ->and($keywords)->toContain('host', 'tcp', 'udp', 'icmp6')
        ;
    });

    test('fills placeholders with their samples and leaves other text alone', function (): void {
        expect(FilterGrammar::fill('src host <ip> and port in [<a> <b>]'))->toBe('src host 192.0.2.10 and port in [80 443]')
            ->and(FilterGrammar::fill('ipv6'))->toBe('ipv6')
            ->and(FilterGrammar::fill('<unknown>'))->toBe('<unknown>')
        ;
    });

    test('every snippet, placeholders filled, and every example passes nfdump -Z', function () use ($real): void {
        if (!is_executable($real)) {
            $this->markTestSkipped('nfdump is not installed here');
        }
        (new ReflectionProperty(FilterValidator::class, 'cache'))->setValue(null, []);

        $filters = [
            ...array_map(static fn (array $f): string => filterGrammarCheckable($f['snippet']), FilterGrammar::fields()),
            ...array_column(FilterGrammar::examples(), 'expression'),
        ];
        // Inside a coroutine, as in the app: an earlier test may have left the runtime hooks on.
        $answers = [];
        Coroutine::run(static function () use ($filters, $real, &$answers): void {
            foreach ($filters as $filter) {
                $answers[$filter] = FilterValidator::validate($filter, $real);
            }
        });
        foreach ($filters as $filter) {
            expect($answers[$filter] ?? null)->toBe(['valid' => true, 'message' => ''], $filter);
        }
    });
});
