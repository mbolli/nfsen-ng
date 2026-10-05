<?php

declare(strict_types=1);

use mbolli\nfsen_ng\processor\FilterValidator;
use mbolli\nfsen_ng\query\ProtocolFilter;

describe('ProtocolFilter', function (): void {
    test('maps every protocol to its term', function (): void {
        expect(ProtocolFilter::term('any'))->toBe('')
            ->and(ProtocolFilter::term('tcp'))->toBe('proto tcp')
            ->and(ProtocolFilter::term('udp'))->toBe('proto udp')
            ->and(ProtocolFilter::term('icmp'))->toBe('proto icmp or proto icmp6')
            ->and(ProtocolFilter::term('other'))->toBe('not (proto tcp or proto udp or proto icmp or proto icmp6)')
        ;
    });

    test('covers exactly the global protocol choices', function (): void {
        expect(ProtocolFilter::PROTOCOLS)->toBe(['any', 'tcp', 'udp', 'icmp', 'other']);

        foreach (ProtocolFilter::PROTOCOLS as $protocol) {
            expect(ProtocolFilter::isValid($protocol))->toBeTrue();
        }
    });

    test('reads the spelling loosely', function (): void {
        expect(ProtocolFilter::term(' TCP '))->toBe('proto tcp');
    });

    test('rejects a protocol it has no term for', function (): void {
        expect(fn () => ProtocolFilter::term('sctp'))->toThrow(InvalidArgumentException::class, 'Unknown protocol, expected one of any, tcp, udp, icmp, other.')
            ->and(fn () => ProtocolFilter::assertValid('TCP'))->toThrow(InvalidArgumentException::class)
            ->and(ProtocolFilter::isValid('sctp'))->toBeFalse()
            ->and(ProtocolFilter::isValid('TCP'))->toBeFalse()
        ;
    });

    test('normalises anything unknown to any', function (): void {
        expect(ProtocolFilter::normalize('UDP'))->toBe('udp')
            ->and(ProtocolFilter::normalize('bogus'))->toBe('any')
            ->and(ProtocolFilter::normalize(''))->toBe('any')
        ;
    });

    test('every term passes nfdump -Z', function (): void {
        $binary = '/usr/local/nfdump/bin/nfdump';
        if (!is_executable($binary)) {
            $this->markTestSkipped('nfdump is not installed here');
        }

        foreach (ProtocolFilter::PROTOCOLS as $protocol) {
            $term = ProtocolFilter::term($protocol);
            if ($term === '') {
                continue;
            }

            expect(FilterValidator::validate($term, $binary))->toBe(['valid' => true, 'message' => '']);
        }
    });
});
