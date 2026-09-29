<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\StarbaseAssets;

const FA_ROOT = __DIR__ . '/../..';
const FA_BANNER = '// Datastar v1.0.4 + Rocket beta.2 (patched: patches/rocket)';

/** A frontend directory holding only a Starbase lock (raw text) and the given files. */
function starbaseFixture(string $lock, array $files = []): string {
    $dir = sys_get_temp_dir() . '/nfsen-starbase-' . bin2hex(random_bytes(6));
    mkdir($dir . '/js/starbase', 0o777, true);
    file_put_contents($dir . '/' . StarbaseAssets::LOCK, $lock);
    foreach ($files as $path) {
        $parent = dirname($dir . '/' . $path);
        is_dir($parent) || mkdir($parent, 0o777, true);
        file_put_contents($dir . '/' . $path, 'export {};');
    }

    return $dir;
}

function starbaseLock(array $components): string {
    return json_encode(['commit' => str_repeat('a', 40), 'components' => $components], JSON_THROW_ON_ERROR);
}

function starbaseWarnings(): array {
    return array_values(array_filter(Debug::drainBuffer(), static fn (array $e): bool => str_starts_with($e['msg'], 'StarbaseAssets:')));
}

describe('the Datastar bundle', function (): void {
    test('only the Rocket bundle ships, never the plain engine', function (): void {
        expect(file_exists(FA_ROOT . '/frontend/js/datastar.js'))->toBeFalse()
            ->and(file_exists(FA_ROOT . '/frontend/js/datastar.js.map'))->toBeFalse()
            ->and(file_exists(FA_ROOT . '/frontend/js/datastar-rocket.js.map'))->toBeTrue()
        ;
    });

    test('is Datastar 1.0.4 with Rocket beta.2 and the patches of patches/rocket', function (): void {
        $first = strtok((string) file_get_contents(FA_ROOT . '/frontend/js/datastar-rocket.js'), "\n");

        expect($first)->toBe(FA_BANNER);
    });

    test('package.json pins the release the patches apply to', function (): void {
        $package = json_decode((string) file_get_contents(FA_ROOT . '/package.json'), true, flags: JSON_THROW_ON_ERROR);

        expect($package['dependencies']['datastar'])->toBe('github:starfederation/datastar#v1.0.4')
            ->and($package['scripts']['postinstall'])->toContain('sh scripts/vendor-rocket.sh --if-tools')
        ;
    });

    test('rocket.lock.json names the bundle bytes and every patch', function (): void {
        $lock = json_decode((string) file_get_contents(FA_ROOT . '/patches/rocket/rocket.lock.json'), true, flags: JSON_THROW_ON_ERROR);
        $bundle = (string) file_get_contents(FA_ROOT . '/frontend/js/datastar-rocket.js');
        $onDisk = array_map('basename', glob(FA_ROOT . '/patches/rocket/*.patch') ?: []);
        sort($onDisk);
        $recorded = [];
        foreach ($lock['patches'] as $patch) {
            $recorded[] = $patch['file'];
            expect(hash_file('sha256', FA_ROOT . '/patches/rocket/' . $patch['file']))->toBe($patch['sha256']);
        }

        expect('// ' . $lock['banner'])->toBe(FA_BANNER)
            ->and(hash('sha256', $bundle))->toBe($lock['sha256'])
            ->and($lock['starbase']['commit'])->toMatch('/^[0-9a-f]{40}$/')
            ->and($onDisk)->not->toBeEmpty()
            ->and($recorded)->toBe($onDisk)
        ;
    });

    test('the import map and the script tag load the same URL', function (): void {
        $layout = (string) file_get_contents(FA_ROOT . '/backend/templates/layout.html.twig');
        preg_match('/"datastar":\s*"([^"]+)"/', $layout, $map);
        preg_match_all('/<script type="module" src="([^"]*datastar[^"]*)"/', $layout, $tags);
        $engine = array_values(array_filter($tags[1], static fn (string $src): bool => !str_contains($src, 'components/')));

        expect($map[1] ?? null)->toBe('{{ basePath }}js/datastar-rocket.js?v={{ shell.assetVersion }}')
            ->and($engine)->toBe([$map[1]])
        ;
    });

    test('ECharts ships its licence and NOTICE, Datastar its licence', function (): void {
        expect(file_get_contents(FA_ROOT . '/frontend/js/echarts.LICENSE'))->toContain('Apache License')
            ->and(file_get_contents(FA_ROOT . '/frontend/js/echarts.NOTICE'))->toContain('Apache ECharts')
            ->and(file_get_contents(FA_ROOT . '/frontend/js/datastar.LICENSE.md'))->toContain('MIT')
        ;
    });
});

describe('StarbaseAssets::modules', function (): void {
    beforeEach(function (): void {
        Debug::getInstance()->setDebug(false);
        Debug::drainBuffer();
        $this->dirs = [];
    });

    afterEach(function (): void {
        Debug::getInstance()->setDebug(true);
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    });

    $component = static fn (bool $load, array $over = []): array => [
        'tag' => 'sb-relative-time', 'version' => '762857ab6a65', 'entry' => 'relative-time.js', 'load' => $load, ...$over,
    ];

    test('lists a component whose lock entry loads', function () use ($component): void {
        $this->dirs[] = $dir = starbaseFixture(
            starbaseLock(['relative-time' => $component(true), 'tabs' => $component(false, ['version' => '2e291e4f298b', 'entry' => 'tabs.js'])]),
            ['js/starbase/relative-time@762857ab6a65/relative-time.js', 'js/starbase/tabs@2e291e4f298b/tabs.js'],
        );

        expect(StarbaseAssets::modules($dir))->toBe(['js/starbase/relative-time@762857ab6a65/relative-time.js'])
            ->and(starbaseWarnings())->toBe([])
        ;
    });

    test('lists nothing while every entry is off', function () use ($component): void {
        $this->dirs[] = $dir = starbaseFixture(
            starbaseLock(['relative-time' => $component(false)]),
            ['js/starbase/relative-time@762857ab6a65/relative-time.js'],
        );

        expect(StarbaseAssets::modules($dir))->toBe([])
            ->and(starbaseWarnings())->toBe([])
        ;
    });

    test('skips an entry whose file is missing, with a warning', function () use ($component): void {
        $this->dirs[] = $dir = starbaseFixture(starbaseLock(['relative-time' => $component(true)]));

        expect(StarbaseAssets::modules($dir))->toBe([])
            ->and(starbaseWarnings())->toHaveCount(1)
        ;
    });

    test('gives nothing and one warning for a lock that is not JSON', function (): void {
        $this->dirs[] = $dir = starbaseFixture('{"components": ');

        expect(StarbaseAssets::modules($dir))->toBe([])
            ->and(starbaseWarnings())->toHaveCount(1)
        ;
    });

    test('gives nothing and one warning without a lock', function (): void {
        $this->dirs[] = $dir = starbaseFixture('');
        unlink($dir . '/' . StarbaseAssets::LOCK);

        expect(StarbaseAssets::modules($dir))->toBe([])
            ->and(starbaseWarnings())->toHaveCount(1)
        ;
    });

    test('skips a bad slug, version or entry that could leave the folder', function (string $slug, array $over) use ($component): void {
        $version = $over['version'] ?? '762857ab6a65';
        $entry = $over['entry'] ?? 'relative-time.js';
        $this->dirs[] = $dir = starbaseFixture(
            starbaseLock([$slug => $component(true, $over)]),
            ["js/starbase/{$slug}@{$version}/README.md", "js/starbase/{$slug}@{$version}/{$entry}"],
        );

        expect(StarbaseAssets::modules($dir))->toBe([])
            ->and(starbaseWarnings())->toHaveCount(1)
        ;
    })->with([
        'uppercase slug' => ['Relative-Time', []],
        'slug with a dot' => ['relative.time', []],
        'short version' => ['relative-time', ['version' => '762857ab']],
        'entry not a module' => ['relative-time', ['entry' => 'relative-time.css']],
        'entry with a path' => ['relative-time', ['entry' => '../x.js']],
        'slug with a newline' => ["relative-time\n", []],
        'version with a newline' => ['relative-time', ['version' => "762857ab6a65\n"]],
        'entry with a newline' => ['relative-time', ['entry' => "relative-time.js\n"]],
    ]);

    test('the real lock loads only files that exist', function (): void {
        $modules = StarbaseAssets::modules(FA_ROOT . '/frontend');
        $missing = array_filter($modules, static fn (string $path): bool => !is_file(FA_ROOT . '/frontend/' . $path));

        expect($modules)->toBeList()
            ->and($missing)->toBe([])
            ->and(starbaseWarnings())->toBe([])
        ;
    })->skip(!is_file(FA_ROOT . '/frontend/' . StarbaseAssets::LOCK), 'no Starbase lock vendored yet');
});
