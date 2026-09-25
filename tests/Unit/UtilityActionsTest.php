<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\UtilityActions;

describe('UtilityActions::hostnameFor()', function (): void {
    test('does not call the resolver when reverse DNS is off', function (): void {
        $calls = [];
        $resolver = function (string $ip) use (&$calls): string {
            $calls[] = $ip;

            return 'host.example';
        };

        $hostname = UtilityActions::hostnameFor('192.0.2.10', false, $resolver);

        expect($calls)->toBe([])
            ->and($hostname)->toBe(UtilityActions::HOSTNAME_RDNS_DISABLED)
        ;
    });

    test('asks the resolver once when reverse DNS is on', function (): void {
        $calls = [];
        $resolver = function (string $ip) use (&$calls): string {
            $calls[] = $ip;

            return 'gw.example.net';
        };

        $hostname = UtilityActions::hostnameFor('2001:db8::1', true, $resolver);

        expect($calls)->toBe(['2001:db8::1'])
            ->and($hostname)->toBe('gw.example.net')
        ;
    });

    test('passes an unresolved answer through', function (): void {
        expect(UtilityActions::hostnameFor('198.51.100.7', true, fn (string $ip): string => UtilityActions::HOSTNAME_UNRESOLVED))
            ->toBe('could not be resolved')
        ;
    });
});
