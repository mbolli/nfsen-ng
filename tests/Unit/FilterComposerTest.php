<?php

declare(strict_types=1);

use mbolli\nfsen_ng\processor\FilterValidator;
use mbolli\nfsen_ng\query\FilterComposer;
use mbolli\nfsen_ng\query\ProtocolFilter;

describe('FilterComposer::and()', function (): void {
    test('is empty without terms', function (): void {
        expect(FilterComposer::and())->toBe('')
            ->and(FilterComposer::and('', '  ', "\n"))->toBe('')
        ;
    });

    test('returns a single term as it is, trimmed', function (): void {
        expect(FilterComposer::and('', ' proto tcp or proto udp ', ''))->toBe('proto tcp or proto udp');
    });

    test('parenthesises every term once there are two', function (): void {
        expect(FilterComposer::and('bytes > 1M', 'src port 53 or dst port 53'))
            ->toBe('(bytes > 1M) and (src port 53 or dst port 53)')
        ;
    });

    test('keeps the order of the terms and skips the empty ones', function (): void {
        expect(FilterComposer::and('bytes > 1M and bytes < 1G', '', ProtocolFilter::term('icmp'), 'host 10.0.0.1'))
            ->toBe('(bytes > 1M and bytes < 1G) and (proto icmp or proto icmp6) and (host 10.0.0.1)')
        ;
    });

    // nfdump reads `#` to the end of the line as a comment, so "(proto tcp # web)" loses its ")".
    test('closes a term with a comment on the next line', function (): void {
        expect(FilterComposer::and('proto tcp # web', 'bytes > 1M'))->toBe("(proto tcp # web\n) and (bytes > 1M)");
    });

    test('skips a term that is only a comment', function (): void {
        expect(FilterComposer::and('bytes > 1M', "# nothing yet\n  "))->toBe('bytes > 1M');
    });

    test('keeps a multi-line term intact', function (): void {
        expect(FilterComposer::and('proto tcp', "src net 10.0.0.0/8\nor dst net 10.0.0.0/8"))
            ->toBe("(proto tcp) and (src net 10.0.0.0/8\nor dst net 10.0.0.0/8)")
        ;
    });

    // `(proto tcp) and (host a) or (host b)` matched host b whatever its protocol.
    test('rejects a term whose parenthesis closes the wrapper', function (): void {
        expect(fn () => FilterComposer::and('bytes > 1M', 'proto tcp', 'host 10.0.0.1) or (host 10.0.0.2'))
            ->toThrow(InvalidArgumentException::class, 'Unbalanced parentheses in the filter.')
            ->and(fn () => FilterComposer::and('proto tcp', 'host 10.0.0.1 or (port 53'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => FilterComposer::and('proto tcp', "port 53 # note\n) or (host 10.0.0.2"))->toThrow(InvalidArgumentException::class)
        ;
    });

    test('rejects an unbalanced term on its own too', function (): void {
        expect(fn () => FilterComposer::and('', 'a) or (b'))->toThrow(InvalidArgumentException::class);
    });

    test('ignores parentheses inside quoted strings and comments', function (): void {
        expect(FilterComposer::and('proto tcp', 'ident "a)b"'))->toBe('(proto tcp) and (ident "a)b")')
            ->and(FilterComposer::and('proto tcp', "ident 'x(y'"))->toBe("(proto tcp) and (ident 'x(y')")
            ->and(FilterComposer::and('proto tcp', 'port 53 # (dns'))->toBe("(proto tcp) and (port 53 # (dns\n)")
            ->and(FilterComposer::and('proto tcp', 'port 53 # "'))->toBe("(proto tcp) and (port 53 # \"\n)")
        ;
    });

    // nfdump ends a quoted string at the end of its line, so the quote cannot hide the `)`.
    test('counts a parenthesis after a quote that runs past its line', function (): void {
        expect(fn () => FilterComposer::and('proto tcp', "ident \"a\n) or (b\""))->toThrow(InvalidArgumentException::class)
            ->and(fn () => FilterComposer::and('proto tcp', 'ident "a) or (b'))->toThrow(InvalidArgumentException::class)
        ;
    });

    test('refuses only terms nfdump -Z refuses on their own', function (): void {
        $binary = '/usr/local/nfdump/bin/nfdump';
        if (!is_executable($binary)) {
            $this->markTestSkipped('nfdump is not installed here');
        }

        foreach (['host 10.0.0.1) or (host 10.0.0.2', 'host 10.0.0.1 or (port 53', "port 53 # note\n) or (host 10.0.0.2", "ident \"a\n) or (b\""] as $term) {
            expect(FilterValidator::validate($term, $binary)['valid'])->toBeFalse();
        }
    });

    test('composes filters nfdump -Z accepts', function (): void {
        $binary = '/usr/local/nfdump/bin/nfdump';
        if (!is_executable($binary)) {
            $this->markTestSkipped('nfdump is not installed here');
        }

        $composed = [
            FilterComposer::and('bytes > 1M and bytes < 1G', ProtocolFilter::term('other'), 'src port 53 or dst port 53'),
            FilterComposer::and(ProtocolFilter::term('icmp'), 'proto tcp # web'),
            FilterComposer::and('bytes > 100', "proto tcp\nand port 80 # http"),
            FilterComposer::and(ProtocolFilter::term('udp'), 'port 53 # (dns'),
        ];
        foreach ($composed as $filter) {
            expect(FilterValidator::validate($filter, $binary))->toBe(['valid' => true, 'message' => '']);
        }
    });
});
