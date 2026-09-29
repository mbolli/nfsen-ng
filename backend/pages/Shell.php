<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\ShellActions;
use mbolli\nfsen_ng\actions\UtilityActions;
use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\HealthMetrics;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\StarbaseAssets;
use mbolli\nfsen_ng\pages\state\ShellState;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * The frame around every page: shell signals and actions, and the render array of 1.5
 * built from the shell, its modules and the pages.
 */
final class Shell {
    /** App-global cache of the footer's capture status, per profile. */
    public const string STATUS_CACHE = 'status_cache';

    public const int STATUS_TTL = 30;

    /**
     * A last capture older than this is a warning, older than an hour an error. The last update is the
     * newest file's interval start, so it is 600 s old just before the next file lands: Health's limit.
     */
    private const int CAPTURE_FRESH = HealthMetrics::STALE_AFTER;

    /** Top-level Twig key of each shell module. */
    private const array MODULE_KEYS = [
        RangeControls::class => 'range',
        TrafficGraph::class => 'graph',
        QueryKit::class => 'querykit',
        FilterDrawer::class => 'drawer',
    ];

    public static function signals(Context $c): void {
        // New signals are pushed on the first sync; the client seeds this one itself (1.2).
        $c->signal(self::defaultPage(), 'page', clientWritable: true)->markSynced();
        $c->signal('', '_error');
        $c->signal(false, 'import_running');

        // serverTz: the container's timezone; nfcapdTz: the one nfcapd names its files in.
        $c->signal(date_default_timezone_get(), 'serverTz');
        $c->signal(Config::nfcapdTimezone()->getName(), 'nfcapdTz');
        $c->signal(Config::$settings->displayTimezone, 'displayTz', clientWritable: true);

        // One query per tab. query_exact tells a known bin count from a byte-sampled
        // estimate; query_kind names the page the query belongs to (1.6).
        $c->signal(false, 'query_running');
        $c->signal(0, 'query_permille');
        $c->signal('', 'query_status');
        $c->signal('', 'query_eta');
        $c->signal(true, 'query_exact');
        $c->signal('', 'query_kind');
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        ShellActions::register($c, $states);
        UtilityActions::register($c, $states);
    }

    /**
     * The render array of 1.5.
     *
     * @return array<string, mixed>
     */
    public static function render(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        Revival::restore($c, $app, $states);

        $fatal = self::fatal($app);
        $importing = self::syncImportRunning($c, $app);
        $activePage = self::activePage($c);
        $now = time();

        self::alertToast($c, $app, $states->shell, $now);

        $sources = $fatal ? [] : self::captureSources($app, self::profile($c), $now);
        $capture = self::captureStatus($sources, $now);
        $daemon = self::daemonStatus($app);

        $data = [
            'shell' => [
                'version' => Config::VERSION,
                'assetVersion' => Config::assetVersion(),
                'starbaseModules' => StarbaseAssets::modules(),
                'fatalError' => $fatal ? (string) $app->globalState('_fatalError') : null,
                'connections' => \count($app->getClients()),
                'activePage' => $activePage,
                'isAnalysis' => PageRegistry::isAnalysis($activePage),
                'pagesMeta' => PageRegistry::meta(),
                'defaults' => [
                    'view' => self::defaultPage(),
                    'theme' => Config::$settings->defaultTheme,
                    'density' => Config::$settings->compactTables ? 'compact' : 'comfortable',
                ],
                'sources' => Config::$settings->sources,
                'ports' => Config::$settings->ports,
                'status' => [
                    'captureLevel' => $capture['level'],
                    'captureLabel' => $capture['label'],
                    'daemonLevel' => $daemon['level'],
                    'daemonLabel' => $daemon['label'],
                ],
                'import' => [
                    'running' => $importing,
                    'progress' => (int) $app->globalState('import_progress', 0),
                    'currentFile' => (string) $app->globalState('import_current_file', ''),
                    'statusText' => (string) $app->globalState('import_status_text', ''),
                    'eta' => (string) $app->globalState('import_eta', ''),
                    'profile' => (string) $app->globalState('import_active_profile', ''),
                ],
                'alertsFiring' => self::alertsFiring($app),
                'healthLevel' => 'unknown',
                'healthIssues' => 0,
                'modalHtml' => $states->shell->modalHtml,
            ],
        ];

        foreach (PageRegistry::MODULES as $module) {
            $data[self::MODULE_KEYS[$module]] = $module::viewData($c, $app, $states, $isUpdate, $activePage);
        }

        $pages = [];
        foreach (PageRegistry::PAGES as $page) {
            $id = $page::id();
            $active = $id === $activePage;
            $pageData = !PageRegistry::lazy() || $active ? $page::viewData($c, $app, $states, $isUpdate) : [];
            $pages[$id] = ['active' => $active, ...$pageData];
        }
        $data['pages'] = $pages;
        $data['shell']['healthLevel'] = HealthPage::level($app, $now, $c);
        $data['shell']['healthIssues'] = self::healthIssues($app, $data['shell']['healthLevel'], $now);

        // Without lazy rendering every page's content is in the document on every render.
        foreach ($states->all() as $id => $state) {
            $state->markRendered($id === 'shell' || !PageRegistry::lazy() || $id === $activePage);
        }

        Revival::persist($c, $app, $states);

        return $data;
    }

    /** The page bare `/` opens: the default view preference, as a page id. */
    public static function defaultPage(): string {
        $page = PageRegistry::fromLegacy(Config::$settings->defaultView);

        return $page !== '' ? $page : OverviewPage::id();
    }

    /** The page the `page` signal names, or the default one when it names none. */
    public static function activePage(Context $c): string {
        $page = $c->getSignal('page')?->string() ?? '';

        return PageRegistry::find($page) !== null ? $page : self::defaultPage();
    }

    /** Rules firing right now, for the sidebar's Alerts count; read from memory, never from disk. */
    public static function alertsFiring(Via $app): int {
        $manager = $app->globalState('alertManager', null);

        return $manager instanceof AlertManager ? $manager->firingCount() : 0;
    }

    /** How many checks sit at the sidebar's Health level, for its accessible text ("Health: 2 warnings"). */
    public static function healthIssues(Via $app, string $level, int $now): int {
        if (!\in_array($level, ['warning', 'error'], true)) {
            return 0;
        }

        return \count(array_filter(
            HealthPage::checks($app, false, $now),
            static fn (array $check): bool => $check['status'] === $level,
        ));
    }

    /**
     * Shows $html in the tab's modal root (D24): kept in ShellState so every later render
     * carries it, patched in now, then opened. $dialogId names the dialog inside $html.
     */
    public static function openModal(Context $c, ShellState $shell, string $html, string $dialogId): void {
        $shell->modalHtml = $html;
        $c->getPatchManager()->queuePatch([
            'type' => 'elements',
            'content' => '<div id="modal-root">' . $html . '</div>',
        ]);
        $id = json_encode($dialogId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $c->execScript("(() => { const d = document.getElementById({$id}); if (d && !d.open) d.showModal(); })()");
    }

    /** Config failed to initialise: renders show the banner and touch no datasource. */
    public static function fatal(Via $app): bool {
        return $app->globalState('_fatalError', null) !== null;
    }

    /** Mirrors the daemons' import locks into import_running for every tab a broadcast reaches. */
    private static function syncImportRunning(Context $c, Via $app): bool {
        /** @var array<string, ImportDaemon> $daemons */
        $daemons = $app->globalState('daemons', []);
        $importing = array_any($daemons, static fn (ImportDaemon $d): bool => $d->isLocked());
        $c->getSignal('import_running')?->setValue($importing, broadcast: false);

        return $importing;
    }

    private static function profile(Context $c): string {
        return $c->getSignal('selected_profile')?->string() ?? Config::$settings->nfdumpProfile;
    }

    /** Shows each fired alert once per tab, through execScript so the toast container's ignore-morph holds. */
    private static function alertToast(Context $c, Via $app, ShellState $shell, int $now): void {
        $fired = $app->globalState('alert_fired', null);
        if (!\is_array($fired)) {
            return;
        }
        $firedTs = (int) ($fired['ts'] ?? 0);
        if ($firedTs <= $shell->lastAlertShown || $now - $firedTs >= 300) {
            return;
        }

        foreach ((array) ($fired['names'] ?? []) as $name) {
            $message = json_encode(
                'Alert fired: ' . (\is_scalar($name) ? (string) $name : ''),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
            );
            $c->execScript("window.showMessage('warning', {$message}, true)");
        }
        $shell->lastAlertShown = $firedTs;
    }

    /**
     * Last datasource update per configured source, shared by every tab of a profile.
     *
     * @return list<array{name: string, last_update: int}>
     */
    private static function captureSources(Via $app, string $profile, int $now): array {
        $cache = $app->globalState(self::STATUS_CACHE, []);
        $cache = \is_array($cache) ? $cache : [];
        $entry = $cache[$profile] ?? null;
        if (\is_array($entry) && \is_int($entry['ts'] ?? null) && $now - $entry['ts'] < self::STATUS_TTL && \is_array($entry['sources'] ?? null)) {
            /** @var list<array{name: string, last_update: int}> */
            return $entry['sources'];
        }

        $sources = [];
        foreach (Config::$settings->sources as $source) {
            $sources[] = ['name' => $source, 'last_update' => Config::$db->last_update($source, 0, $profile)];
        }
        // selected_profile is client-writable: expired entries go, so posted names cannot pile up.
        $cache = array_filter($cache, static fn (mixed $e): bool => \is_array($e) && \is_int($e['ts'] ?? null) && $now - $e['ts'] < self::STATUS_TTL);
        $cache[$profile] = ['ts' => $now, 'sources' => $sources];
        $app->setGlobalState(self::STATUS_CACHE, $cache);

        return $sources;
    }

    /**
     * Capture health, inferred from the newest datasource update of any source.
     *
     * @param list<array{name: string, last_update: int}> $sources
     *
     * @return array{level: 'error'|'neutral'|'success'|'warning', label: string}
     */
    private static function captureStatus(array $sources, int $now): array {
        $last = $sources === [] ? 0 : max(array_column($sources, 'last_update'));
        if ($last === 0) {
            return ['level' => 'neutral', 'label' => 'nfcapd: no data yet'];
        }

        $age = $now - $last;
        if ($age < self::CAPTURE_FRESH) {
            return ['level' => 'success', 'label' => 'nfcapd: last capture ' . HealthChecker::ageStr($age) . ' ago'];
        }

        return $age < 3600
            ? ['level' => 'warning', 'label' => 'nfcapd: no capture in ' . HealthChecker::ageStr($age)]
            : ['level' => 'error', 'label' => 'nfcapd: no capture in ' . HealthChecker::ageStr($age)];
    }

    /** @return array{level: 'error'|'success'|'warning', label: string} */
    private static function daemonStatus(Via $app): array {
        /** @var array<string, ImportDaemon> $daemons */
        $daemons = $app->globalState('daemons', []);
        if ((bool) $app->globalState('daemon_disabled', false) || $daemons === []) {
            return ['level' => 'error', 'label' => 'Import daemon: disabled (NFSEN_SKIP_DAEMON)'];
        }

        if (!array_all($daemons, static fn (ImportDaemon $d): bool => $d->isDaemonReady())) {
            return ['level' => 'warning', 'label' => 'Import daemon: initializing…'];
        }

        $profiles = \count($daemons);
        $watches = array_sum(array_map(static fn (ImportDaemon $d): int => $d->getWatchCount(), $daemons));

        return [
            'level' => 'success',
            'label' => "Import daemon: {$profiles} profile" . ($profiles !== 1 ? 's' : '') . ", watching {$watches} dir" . ($watches !== 1 ? 's' : ''),
        ];
    }
}
