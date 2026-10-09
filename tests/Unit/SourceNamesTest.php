<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * The whole page as app.php renders it, for the given sources: `source` or `source:Name` entries.
 *
 * @param list<string> $sources
 */
function sourceNamesPage(array $sources, string $page = 'overview'): string {
    $settings = isset(Config::$settings) ? Config::$settings : null;
    $prefs = isset(Config::$prefsFile) ? Config::$prefsFile : null;
    Config::$prefsFile = sys_get_temp_dir() . '/nfsen-source-names-missing.json';
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => $sources, 'ports' => [80]],
        'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-source-names-missing', 'profile' => 'live'],
    ]);
    $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context('ctx-sources-' . bin2hex(random_bytes(3)), '/', $app);
    $states = new PageStates();

    try {
        Shell::signals($c);
        foreach ([...PageRegistry::MODULES, ...PageRegistry::PAGES] as $module) {
            $module::signals($c);
        }
        Shell::register($c, $app, $states);
        foreach ([...PageRegistry::MODULES, ...PageRegistry::PAGES] as $module) {
            $module::register($c, $app, $states);
        }
        $c->getSignal('page')?->setValue($page);

        return $c->render('layout.html.twig', Shell::render($c, $app, $states, false));
    } finally {
        if ($settings !== null) {
            Config::$settings = $settings;
        }
        if ($prefs !== null) {
            Config::$prefsFile = $prefs;
        }
    }
}

/** @return array<string, array{label: string, title: null|string}> the sources menu's boxes: value => label and title */
function sourceNamesMenu(string $html): array {
    $menu = [];
    foreach (HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('#sourcesMenuList input[name=globalSource]') as $box) {
        $label = $box->parentElement;
        $menu[$box->getAttribute('value') ?? ''] = ['label' => trim($label?->textContent ?? ''), 'title' => $label?->getAttribute('title')];
    }

    return $menu;
}

// #177: nfcapd -M names a source after its exporter's address, so the menu shows a configured name instead.
describe('source names', function (): void {
    test('the sources menu shows a source by its name, keeps the source as the value and names it in the title', function (): void {
        expect(sourceNamesMenu(sourceNamesPage(['10-20-100-3:dc1rt310', '10-60-119-66'])))->toBe([
            '10-20-100-3' => ['label' => 'dc1rt310', 'title' => '10-20-100-3'],
            '10-60-119-66' => ['label' => '10-60-119-66', 'title' => null],
        ]);
    });
});
