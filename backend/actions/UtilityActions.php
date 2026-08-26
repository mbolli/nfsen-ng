<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\IpLookup;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\processor\Nfdump;
use Mbolli\PhpVia\Context;

/**
 * Utility action registrations: ip-info and kill-nfdump.
 */
final class UtilityActions {
    /**
     * Register ip-info and kill-nfdump actions.
     *
     * @param list<array{id: string, type: string, message: string}> $flowNotifications
     * @param list<array{id: string, type: string, message: string}> $statsNotifications
     */
    public static function register(Context $c, array &$flowNotifications, array &$statsNotifications): void {
        // IP info: geo lookup + hostname resolution — pushes a rendered modal fragment
        $c->action(static function (Context $c): void {
            $ip = $c->input('ip') ?? '';

            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return;
            }

            $isPrivate = IpLookup::isPrivate($ip);

            $netboxData = $isPrivate ? IpLookup::netbox($ip) : null;
            $geoData = $isPrivate ? [] : IpLookup::geo($ip);

            $hostname = @gethostbyaddr($ip);
            if ($hostname === $ip || $hostname === false) {
                $esc = escapeshellarg((string) $ip);
                exec("host -W 5 {$esc} 2>&1", $out, $ret);
                if ($ret === 0) {
                    $domains = [];
                    foreach ($out as $line) {
                        if (preg_match('/domain name pointer (.*)\./', $line, $m)) {
                            $domains[] = $m[1];
                        }
                    }
                    $hostname = $domains ? implode(', ', $domains) : 'could not be resolved';
                } else {
                    $hostname = 'could not be resolved';
                }
            }

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

        // Kill the running nfdump process — sends SIGTERM to the PID in Nfdump::$runningPid.
        // Safe because SWOOLE_HOOK_ALL makes stream_get_contents coroutine-yielding, so this
        // action runs concurrently with a blocked flow/stats action.
        $c->action(static function (Context $c) use (&$flowNotifications, &$statsNotifications): void {
            // Raise the cancel flag first. A chunked run (the filtered graph) forks one
            // nfdump per time bin, so SIGTERM alone only ends the bin in flight and the
            // loop marches straight on to the next one — it has to be told to stop.
            QueryCancel::request($c->getId());

            $pid = Nfdump::$runningPid;
            if ($pid !== null && $pid > 0) {
                posix_kill($pid, SIGTERM);
                $msg = 'nfdump process (PID ' . $pid . ') was killed.';
                $flowNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => $msg]];
                $statsNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => $msg]];
            }
            $c->sync();
        }, 'kill-nfdump');
    }
}
