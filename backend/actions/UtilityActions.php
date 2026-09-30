<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\IpLookup;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\pages\ConversationsPage;
use mbolli\nfsen_ng\pages\FlowsPage;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\pages\TalkersPage;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use Mbolli\PhpVia\Context;

/**
 * Utility action registrations: ip-info and kill-nfdump.
 */
final class UtilityActions {
    public const string HOSTNAME_UNRESOLVED = 'could not be resolved';

    public const string HOSTNAME_RDNS_DISABLED = 'not looked up (reverse DNS is turned off)';

    /** Register ip-info and kill-nfdump actions. */
    public static function register(Context $c, PageStates $states): void {
        // IP info: Netbox for private addresses, geolocation for public ones, and the hostname,
        // shown in the tab's modal (4.0.6).
        $c->action(static function (Context $c) use ($states): void {
            $ip = trim((string) ($c->input('ip') ?? ''));
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return;
            }

            try {
                Shell::openModal($c, $states->shell, $c->render('partials/ip-info-modal.html.twig', self::ipInfoView($ip)), 'ip-modal-inner');
            } catch (\Throwable $e) {
                $c->getSignal('_error')?->setValue('IP info for ' . $ip . ' failed: ' . $e->getMessage(), broadcast: false);
                $c->syncSignals();
            }
        }, 'ip-info');

        // Kill this tab's query: NfdumpSlots::kill() SIGTERMs every nfdump registered under its handle.
        $c->action(static function (Context $c) use ($states): void {
            // Raise the cancel flag first. A chunked run (the filtered graph) forks one
            // nfdump per time bin, so SIGTERM alone only ends the bin in flight and the
            // loop marches straight on to the next one, so it has to be told to stop.
            QueryCancel::request($c->getId());

            // This tab's own run and nothing else. The fallback to the 'default' handle that
            // used to be here belongs to the import daemon and every MCP call, so pressing
            // Kill with no query of your own in flight SIGTERMed theirs.
            $pids = NfdumpSlots::kill($c->getId());
            if ($pids !== []) {
                foreach (self::killNoticePages($c) as $page) {
                    $state = $states->for($page);
                    $state?->clearNotifications();
                    $state?->notify('warning', self::killNotice($pids));
                }
            }
            $c->sync();
        }, 'kill-nfdump');
    }

    /**
     * What Kill stopped: a split query or a filtered build runs several nfdump processes at once.
     *
     * @param non-empty-list<int> $pids
     */
    public static function killNotice(array $pids): string {
        return \count($pids) === 1
            ? 'nfdump process (PID ' . $pids[0] . ') was killed.'
            : \count($pids) . ' nfdump processes (PIDs ' . implode(', ', $pids) . ') were killed.';
    }

    /** The page whose query was killed, from query_kind; the active page when the kind is unknown. */
    public static function killNoticePage(Context $c): string {
        return PageRegistry::pageForKind($c->getSignal('query_kind')?->string() ?? '') ?? Shell::activePage($c);
    }

    /**
     * The pages that show the Kill notice. The old layout renders no Overview or monitor
     * page notices, so there it goes to Flows and Top Talkers, where it always went.
     *
     * @return list<string>
     */
    public static function killNoticePages(Context $c): array {
        $page = self::killNoticePage($c);
        if (PageRegistry::lazy() || \in_array($page, [FlowsPage::id(), TalkersPage::id(), ConversationsPage::id()], true)) {
            return [$page];
        }

        return [FlowsPage::id(), TalkersPage::id()];
    }

    /**
     * Data for partials/ip-info-modal.html.twig. Values are raw: the template escapes them.
     *
     * @return array{ip: string, hostname: string, hostnameFound: bool, isPrivate: bool, netboxData: array<string, mixed>, geoData: array<string, mixed>, geoSource: string}
     */
    public static function ipInfoView(string $ip): array {
        $isPrivate = IpLookup::isPrivate($ip);
        $geoData = $isPrivate ? [] : IpLookup::geo($ip);
        $hostname = self::hostnameFor($ip, Config::$settings->rdnsEnabled, self::resolveHostname(...));

        return [
            'ip' => $ip,
            'hostname' => $hostname,
            'hostnameFound' => !\in_array($hostname, [self::HOSTNAME_UNRESOLVED, self::HOSTNAME_RDNS_DISABLED], true),
            'isPrivate' => $isPrivate,
            'netboxData' => ($isPrivate ? IpLookup::netbox($ip) : null) ?? [],
            'geoData' => $geoData,
            'geoSource' => $geoData === [] ? '' : self::geoSource($geoData, IpLookup::geoUrl($ip)),
        ];
    }

    /**
     * Where a geolocation answer came from: the local database, or the web service's host.
     *
     * @param array<string, mixed> $geoData
     */
    public static function geoSource(array $geoData, string $geoUrl): string {
        if (($geoData['source'] ?? null) === IpLookup::SOURCE_MAXMIND) {
            return 'MaxMind database';
        }
        $host = parse_url($geoUrl, PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? $host : 'geolocation service';
    }

    /**
     * The hostname shown for an IP; $resolver is only called when reverse DNS is on.
     *
     * @param callable(string): string $resolver
     */
    public static function hostnameFor(string $ip, bool $rdnsEnabled, callable $resolver): string {
        return $rdnsEnabled ? $resolver($ip) : self::HOSTNAME_RDNS_DISABLED;
    }

    /** Reverse lookup through the system resolver, then through the `host` tool. */
    private static function resolveHostname(string $ip): string {
        $hostname = @gethostbyaddr($ip);
        if ($hostname !== false && $hostname !== $ip) {
            return $hostname;
        }

        $esc = escapeshellarg($ip);
        exec("host -W 5 {$esc} 2>&1", $out, $ret);
        if ($ret !== 0) {
            return self::HOSTNAME_UNRESOLVED;
        }
        $domains = [];
        foreach ($out as $line) {
            if (preg_match('/domain name pointer (.*)\./', $line, $m)) {
                $domains[] = $m[1];
            }
        }

        return $domains !== [] ? implode(', ', $domains) : self::HOSTNAME_UNRESOLVED;
    }
}
