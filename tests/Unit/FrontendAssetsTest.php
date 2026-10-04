<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\StarbaseAssets;

const FA_ROOT = __DIR__ . '/../..';

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
    test('comes from php-via, so the front end ships no engine of its own', function (): void {
        expect(glob(FA_ROOT . '/frontend/js/datastar*'))->toBe([])
            ->and((string) file_get_contents(FA_ROOT . '/backend/app.php'))->toContain('->withDatastarRocket()')
        ;
    });

    test('the layout loads it through via_head and via_foot only', function (): void {
        $layout = (string) file_get_contents(FA_ROOT . '/backend/templates/layout.html.twig');

        expect($layout)->toMatch('/<meta charset="utf-8">\s*(\{#.*?#\}\s*)?\{\{ via_head\(\) \}\}/s')
            ->and(substr_count($layout, '{{ via_foot() }}'))->toBe(1)
            ->and($layout)->not->toContain('type="importmap"')
            ->and(preg_match('/<script type="module" src="(?![^"]*components\/)[^"]*datastar[^"]*"/', $layout))->toBe(0)
        ;
    });

    test('ECharts ships its licence and NOTICE', function (): void {
        expect(file_get_contents(FA_ROOT . '/frontend/js/echarts.LICENSE'))->toContain('Apache License')
            ->and(file_get_contents(FA_ROOT . '/frontend/js/echarts.NOTICE'))->toContain('Apache ECharts')
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
