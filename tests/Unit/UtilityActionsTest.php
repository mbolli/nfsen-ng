<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\UtilityActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\IpLookup;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\NfdumpSlots;

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

describe('UtilityActions::geoSource()', function (): void {
    test('names the local database for a MaxMind answer and the host of the web service otherwise', function (): void {
        expect(UtilityActions::geoSource(['source' => IpLookup::SOURCE_MAXMIND], 'https://ipapi.co/203.0.113.9/json/'))->toBe('MaxMind database')
            ->and(UtilityActions::geoSource(['country' => 'CH'], 'https://ipapi.co/203.0.113.9/json/'))->toBe('ipapi.co')
            ->and(UtilityActions::geoSource(['country' => 'CH'], 'not a url'))->toBe('geolocation service')
        ;
    });
});

describe('UtilityActions::ipInfoView()', function (): void {
    test('a private address with reverse DNS off has no hostname, no geolocation and no network call', function (): void {
        $settings = new ReflectionProperty(Config::class, 'settings');
        $before = $settings->isInitialized() ? Config::$settings : null;
        Config::$settings = Settings::fromArray(['general' => ['sources' => ['gw']]])->withRdnsEnabled(false);

        try {
            $view = UtilityActions::ipInfoView('192.168.1.10');
        } finally {
            if ($before !== null) {
                Config::$settings = $before;
            }
        }

        expect($view)->toMatchArray([
            'ip' => '192.168.1.10',
            'hostname' => UtilityActions::HOSTNAME_RDNS_DISABLED,
            'hostnameFound' => false,
            'isPrivate' => true,
            'netboxData' => [],
            'geoData' => [],
            'geoSource' => '',
        ]);
    });
});

describe('UtilityActions::killNotice()', function (): void {
    test('names the one process of a plain query', function (): void {
        expect(UtilityActions::killNotice([4242]))->toBe('nfdump process (PID 4242) was killed.');
    });

    test('names every process of a split query or a filtered build', function (): void {
        expect(UtilityActions::killNotice([11, 12, 13]))->toBe('3 nfdump processes (PIDs 11, 12, 13) were killed.');
    });
});

describe('NfdumpSlots::kill()', function (): void {
    test('signals and reports every process of the query', function (): void {
        $children = [];
        foreach ([1, 2] as $_) {
            $proc = proc_open(['sleep', '30'], [], $pipes);
            $children[] = $proc;
            NfdumpSlots::register('kill-all', proc_get_status($proc)['pid']);
        }
        $pids = array_map(static fn ($p): int => proc_get_status($p)['pid'], $children);

        try {
            expect(NfdumpSlots::kill('kill-all'))->toBe($pids);
            foreach ($children as $proc) {
                for ($i = 0; $i < 50 && proc_get_status($proc)['running']; ++$i) {
                    usleep(20_000);
                }
                expect(proc_get_status($proc)['running'])->toBeFalse();
            }
        } finally {
            foreach ($pids as $pid) {
                NfdumpSlots::unregister('kill-all', $pid);
            }
            foreach ($children as $proc) {
                proc_terminate($proc, SIGKILL);
                proc_close($proc);
            }
        }
    });
});
