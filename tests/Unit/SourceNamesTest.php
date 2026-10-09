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

/** @return array{attributes: array<string, string>, options: list<array<string, string>>} the sources picker as rendered */
function sourceNamesPicker(string $html): array {
    $picker = HTMLDocument::createFromString($html, LIBXML_NOERROR)->getElementById('sourcesSelect');
    $attributes = [];
    foreach ($picker?->attributes ?? [] as $attribute) {
        $attributes[$attribute->name] = $attribute->value;
    }

    return ['attributes' => $attributes, 'options' => json_decode($attributes['options'] ?? '[]', true, flags: JSON_THROW_ON_ERROR)];
}

// #177: nfcapd -M names a source after its exporter's address, so the picker shows a configured name instead.
describe('source names', function (): void {
    test('the sources picker shows a source by its name, with the source as the text a search also matches', function (): void {
        $picker = sourceNamesPicker(sourceNamesPage(['10-20-100-3:dc1rt310', '10-60-119-66']));

        expect($picker['options'])->toBe([
            ['value' => '10-20-100-3', 'label' => 'dc1rt310', 'description' => '10-20-100-3'],
            ['value' => '10-60-119-66', 'label' => '10-60-119-66'],
        ]);
    });

    test('the picker searches, picks several and says All sources when none is picked', function (): void {
        $picker = sourceNamesPicker(sourceNamesPage(['gw1', 'gw2']));

        expect($picker['attributes'])->toHaveKeys(['multiple', 'searchable', 'clearable', 'actions'])
            ->and($picker['attributes']['placeholder'] ?? null)->toBe('All sources')
            ->and($picker['attributes']['summary'] ?? null)->toBe('{count} of {total} sources')
        ;
    });
});
