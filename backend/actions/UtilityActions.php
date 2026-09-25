<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\IpLookup;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use Mbolli\PhpVia\Context;

/**
 * Utility action registrations: ip-info and kill-nfdump.
 */
final class UtilityActions {
    public const string HOSTNAME_UNRESOLVED = 'could not be resolved';

    public const string HOSTNAME_RDNS_DISABLED = 'not looked up (reverse DNS is turned off)';

    /**
     * Register ip-info and kill-nfdump actions.
     *
     * @param list<array{id: string, type: string, message: string}> $flowNotifications
     * @param list<array{id: string, type: string, message: string}> $statsNotifications
     */
    public static function register(Context $c, array &$flowNotifications, array &$statsNotifications): void {
        // IP info: geo lookup and hostname resolution, pushed as a rendered modal fragment
        $c->action(static function (Context $c): void {
            $ip = $c->input('ip') ?? '';

            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return;
            }

            $isPrivate = IpLookup::isPrivate($ip);

            $netboxData = $isPrivate ? IpLookup::netbox($ip) : null;
            $geoData = $isPrivate ? [] : IpLookup::geo($ip);

            $hostname = self::hostnameFor($ip, Config::$settings->rdnsEnabled, self::resolveHostname(...));

            $modalHtml = $c->render('partials/ip-info-modal.html.twig', [
                'ip' => htmlspecialchars($ip, ENT_QUOTES),
                'hostname' => htmlspecialchars((string) $hostname, ENT_QUOTES),
                'geoData' => $geoData,
                'netboxData' => $netboxData ?? [],
            ]);

            $c->getPatchManager()->queuePatch([
                'type' => 'elements',
                'content' => $modalHtml,
            ]);

            $c->execScript('document.getElementById("ip-modal-inner").showModal()');
        }, 'ip-info');

        // Kill the running nfdump process: sends SIGTERM to the PID in Nfdump::$runningPid.
        // Safe because SWOOLE_HOOK_ALL makes stream_get_contents coroutine-yielding, so this
        // action runs concurrently with a blocked flow/stats action.
        $c->action(static function (Context $c) use (&$flowNotifications, &$statsNotifications): void {
            // Raise the cancel flag first. A chunked run (the filtered graph) forks one
            // nfdump per time bin, so SIGTERM alone only ends the bin in flight and the
            // loop marches straight on to the next one, so it has to be told to stop.
            QueryCancel::request($c->getId());

            // This tab's own run and nothing else. The fallback to the 'default' handle that
            // used to be here belongs to the import daemon and every MCP call, so pressing
            // Kill with no query of your own in flight SIGTERMed theirs.
            $pid = NfdumpSlots::kill($c->getId());
            if ($pid !== null && $pid > 0) {
                $msg = 'nfdump process (PID ' . $pid . ') was killed.';
                $flowNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => $msg]];
                $statsNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => $msg]];
            }
            $c->sync();
        }, 'kill-nfdump');
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
