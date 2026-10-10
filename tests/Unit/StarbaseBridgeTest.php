<?php

declare(strict_types=1);

/**
 * The theming bridge (ROCKET-SPEC 5.2, 8.3): every token a vendored Starbase module reads is mapped in
 * frontend/css/starbase.css, and no module carries a pixel trait the token map cannot neutralise (5.6).
 */
final class StarbaseBridgeTest {
    public const string ROOT = __DIR__ . '/../..';
    public const string DIR = self::ROOT . '/frontend/js/starbase';
    public const string CSS = self::ROOT . '/frontend/css/starbase.css';
    public const string TOKENS = self::ROOT . '/frontend/css/tokens.css';

    /**
     * Per-component size and duration knobs whose defaults fit, so the bridge leaves them unset: token => the
     * one tag that may read it. The odometer's easing is the curve of its duration, cubic-bezier by default.
     */
    public const array KNOBS = [
        '--sb-code-editor-font-size' => 'sb-code-editor',
        '--sb-code-editor-height' => 'sb-code-editor',
        '--sb-code-editor-min-height' => 'sb-code-editor',
        '--sb-code-playground-height' => 'sb-code-playground',
        '--sb-details-duration' => 'sb-details',
        '--sb-gauge-size' => 'sb-gauge',
        '--sb-meter-height' => 'sb-meter',
        '--sb-meter-width' => 'sb-meter',
        '--sb-nebula-height' => 'sb-nebula',
        '--sb-odometer-duration' => 'sb-odometer',
        '--sb-odometer-easing' => 'sb-odometer',
        '--sb-odometer-perspective' => 'sb-odometer',
        '--sb-odometer-window' => 'sb-odometer',
        '--sb-pixel-board-size' => 'sb-pixel-board',
        '--sb-sparkline-height' => 'sb-sparkline',
        '--sb-sparkline-width' => 'sb-sparkline',
        '--sb-starfield-height' => 'sb-starfield',
        '--sb-toast-inset' => 'sb-toast',
        '--sb-toast-width' => 'sb-toast',
        '--sb-voxel-size' => 'sb-voxel',
    ];

    /** Unset on purpose, so components inherit nfsen-ng's font (ROCKET-SPEC 5.2). */
    public const array INHERITED = ['--sb-font-body', '--sb-font-display', '--sb-font-ui'];

    /**
     * Pixel traits reviewed and accepted, per module ("<slug>/<file>"): trait => reason. Any other one keeps a
     * component out until Starbase fixes it (ROCKET-SPEC 3.8).
     *
     * @var array<string, array<string, string>>
     */
    public const array PIXEL_ALLOW = [
        'toast/toast.js' => [
            'steps()' => 'Only under prefers-reduced-motion: the countdown bar jumps down in fifths instead of sliding.',
        ],
    ];

    /** @return array<string, string> the --sb-* declarations of the bridge's :root block, name => value */
    public static function mapping(): array {
        return self::defined((string) file_get_contents(self::CSS), ':root');
    }

    /**
     * The --sb-* tokens a stylesheet gives a tag, name => value: those of :root, plus those of top-level rules that
     * name the tag (`sb-range { ... }`) unless it slots content (ROCKET-SPEC 5.3.2).
     *
     * @return array<string, string>
     */
    public static function defined(string $css, string $tag, bool $slots = false): array {
        $out = [];
        foreach (self::topLevelRules(self::withoutComments($css)) as [$selectors, $body]) {
            $names = array_map(trim(...), explode(',', $selectors));
            if (!in_array(':root', $names, true) && ($slots || !in_array($tag, $names, true))) {
                continue;
            }
            preg_match_all('/(--sb-[a-z0-9-]+)\s*:\s*([^;]+)(?:;|$)/', $body, $m, PREG_SET_ORDER);
            foreach ($m as [, $name, $value]) {
                $out[$name] = trim($value);
            }
        }

        return $out;
    }

    /**
     * Style rules outside any at-rule, as [selector list, declarations]: what a nested block sets, under a media
     * query or a nested selector, is left out.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function topLevelRules(string $css): array {
        $rules = [];
        $depth = 0;
        $prelude = $body = $pending = '';
        for ($i = 0, $n = strlen($css); $i < $n; ++$i) {
            $ch = $css[$i];
            if ($ch === '{') {
                if (++$depth === 1) {
                    $body = '';
                }
                $pending = '';
            } elseif ($ch === '}' && $depth > 0) {
                if (--$depth === 0) {
                    if (!str_starts_with(trim($prelude), '@')) {
                        $rules[] = [trim($prelude), $body . $pending];
                    }
                    $prelude = '';
                }
                $pending = '';
            } elseif ($depth === 0) {
                $prelude = $ch === ';' ? '' : $prelude . $ch;
            } elseif ($depth === 1) {
                $pending .= $ch;
                if ($ch === ';') {
                    $body .= $pending;
                    $pending = '';
                }
            }
        }

        return $rules;
    }

    public static function withoutComments(string $css): string {
        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    /**
     * "<slug>@<version>/<file>" => every vendored .js/.mjs file, its component's tag and whether that component
     * renders a slot.
     *
     * @return array<string, array{path: string, tag: string, slots: bool}>
     */
    public static function modules(): array {
        $lock = json_decode((string) file_get_contents(self::DIR . '/starbase.lock.json'), true, 16, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($lock['components'] ?? [] as $slug => $c) {
            $folder = $slug . '@' . $c['version'];
            expect(is_dir(self::DIR . '/' . $folder))->toBeTrue("{$folder} is missing");
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DIR . '/' . $folder, FilesystemIterator::SKIP_DOTS));
            $component = [];
            foreach ($files as $file) {
                if ($file->isFile() && preg_match('/\.m?js$/', $file->getFilename()) === 1) {
                    $component[$folder . substr($file->getPathname(), strlen(self::DIR . '/' . $folder))] = $file->getPathname();
                }
            }
            $slots = array_filter($component, static fn (string $path): bool => preg_match('/<slot\b/', (string) file_get_contents($path)) === 1) !== [];
            foreach ($component as $name => $path) {
                $out[$name] = ['path' => $path, 'tag' => $c['tag'], 'slots' => $slots];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Tokens a module of $tag reads (var() or a quoted name) that are not defined for it, a knob of it or inherited on
     * purpose. A fallback does not count: a colour knob that falls back to a mapped token still needs a decision.
     *
     * @param array<string, string> $defined
     *
     * @return list<string>
     */
    public static function unmappedTokens(string $code, string $tag, array $defined): array {
        preg_match_all('/var\(\s*(--sb-[a-z0-9-]+)/', $code, $vars);
        preg_match_all('/([\'"`])(--sb-[a-z0-9-]+)\1/', $code, $names);
        $out = array_filter(
            array_unique([...$vars[1], ...$names[2]]),
            static fn (string $t): bool => !isset($defined[$t]) && (self::KNOBS[$t] ?? null) !== $tag && !in_array($t, self::INHERITED, true),
        );
        sort($out);

        return $out;
    }

    /**
     * Pixel traits the token map cannot neutralise (ROCKET-SPEC 5.6), trait => line numbers. A polygon(), or a
     * notch helper's argument, bypasses --sb-notch when it has a px offset and no path to --sb-notch.
     *
     * @return array<string, list<int>>
     */
    public static function pixelTraits(string $code): array {
        $hits = [];
        $line = static fn (int $offset): int => substr_count($code, "\n", 0, $offset) + 1;
        $patterns = [
            'pixelated' => '/\bpixelated\b/',
            'crisp edges' => '/\bcrisp-?edges\b/i',
            'uppercase' => '/text-transform\s*:\s*uppercase\b/i',
        ];
        foreach ($patterns as $trait => $re) {
            preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $offset]) {
                $hits[$trait][] = $line($offset);
            }
        }
        $declared = self::declarations($code);
        // Stepped motion counts unless its step count follows the notch (sb-select's spinner: thousands of steps at 0).
        preg_match_all('/\bsteps\(/', $code, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$match, $offset]) {
            if (!self::followsNotch(self::balanced($code, $offset + strlen($match)), $declared)) {
                $hits['steps()'][] = $line($offset);
            }
        }
        $fixed = static fn (string $args): bool => self::hasPx($args, $declared) && !self::followsNotch($args, $declared);
        preg_match_all('/\bpolygon\(/', $code, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$match, $offset]) {
            if ($fixed(self::balanced($code, $offset + strlen($match)))) {
                $hits['fixed polygon'][] = $line($offset);
            }
        }
        preg_match_all('/\bnotch\(\s*([\'"`])(.*?)\1\s*\)/', $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $call) {
            if ($fixed($call[2][0])) {
                $hits['fixed polygon'][] = $line($call[0][1]);
            }
        }

        return array_map(static function (array $lines): array {
            sort($lines);

            return $lines;
        }, $hits);
    }

    /** @return array<string, list<string>> every value the module declares for each custom property */
    public static function declarations(string $code): array {
        preg_match_all('/(?<![\w-])(--[a-z0-9_-]+)\s*:\s*([^;}"\n]+)/i', $code, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $name, $value]) {
            $out[$name][] = trim($value);
        }

        return $out;
    }

    /**
     * Whether a value reads --sb-notch, directly or through a variable whose every declaration does.
     *
     * @param array<string, list<string>> $declared
     * @param array<string, true>         $seen
     */
    public static function followsNotch(string $value, array $declared, array $seen = []): bool {
        if (preg_match('/var\(\s*--sb-notch\b/', $value) === 1) {
            return true;
        }
        preg_match_all('/var\(\s*(--[a-z0-9_-]+)/i', $value, $m);
        foreach ($m[1] as $name) {
            if (isset($seen[$name]) || !isset($declared[$name])) {
                continue;
            }
            $all = true;
            foreach ($declared[$name] as $v) {
                $all = $all && self::followsNotch($v, $declared, $seen + [$name => true]);
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value has a px length, literally or in a declaration of a variable it reads.
     *
     * @param array<string, list<string>> $declared
     * @param array<string, true>         $seen
     */
    public static function hasPx(string $value, array $declared, array $seen = []): bool {
        if (preg_match('/\d(?:\.\d+)?px\b/', $value) === 1) {
            return true;
        }
        preg_match_all('/var\(\s*(--[a-z0-9_-]+)/i', $value, $m);
        foreach ($m[1] as $name) {
            foreach (isset($seen[$name]) ? [] : $declared[$name] ?? [] as $v) {
                if (self::hasPx($v, $declared, $seen + [$name => true])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The text from $start up to the parenthesis that closes the one before it. */
    public static function balanced(string $code, int $start): string {
        $depth = 1;
        for ($i = $start, $n = strlen($code); $i < $n; ++$i) {
            if ($code[$i] === '(') {
                ++$depth;
            } elseif ($code[$i] === ')' && --$depth === 0) {
                return substr($code, $start, $i - $start);
            }
        }

        return substr($code, $start);
    }
}

describe('Starbase theming bridge', function (): void {
    test('pixel corners are off, frames are hairlines and fonts are inherited', function (): void {
        $mapping = StarbaseBridgeTest::mapping();

        expect($mapping)->toHaveKey('--sb-notch')
            ->and($mapping['--sb-notch'])->toBe('0')
            ->and($mapping['--sb-frame-step'] ?? null)->toBe('1px')
            ->and(array_intersect(array_keys($mapping), StarbaseBridgeTest::INHERITED))->toBe([])
        ;
    });

    test('the map refers to nfsen-ng tokens that tokens.css defines for both themes, other rules to those or mapped ones', function (): void {
        preg_match('/^:root\s*\{(.*?)^\}/ms', (string) file_get_contents(StarbaseBridgeTest::TOKENS), $root);
        $tokens = $root[1] ?? '';
        $mapping = StarbaseBridgeTest::mapping();
        $css = StarbaseBridgeTest::withoutComments((string) file_get_contents(StarbaseBridgeTest::CSS));
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/', $css, $m);
        $referenced = array_values(array_unique($m[1]));
        preg_match_all('/var\(\s*(--sb-[a-z0-9-]+)/', implode(' ', $mapping), $inMap);

        expect(count($mapping))->toBeGreaterThan(40)
            ->and($tokens)->not->toBeEmpty()
            ->and($referenced)->not->toBeEmpty()
            ->and($inMap[1])->toBe([], 'the :root block maps to nfsen-ng tokens only')
        ;
        foreach ($referenced as $name) {
            if (str_starts_with($name, '--sb-')) {
                expect(isset($mapping[$name]))->toBeTrue("{$name} is read by a rule but not mapped");

                continue;
            }
            expect(preg_match('/^\s*' . preg_quote($name, '/') . '\s*:/m', $tokens))->toBe(1, "{$name} is not in the unscoped :root block of tokens.css");
        }
        expect(preg_match('/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(|!important/i', $css))->toBe(0);
    });

    test('every token a vendored module reads is mapped, a knob of its own component, or inherited on purpose', function (): void {
        $modules = StarbaseBridgeTest::modules();
        $css = (string) file_get_contents(StarbaseBridgeTest::CSS);

        expect($modules)->not->toBeEmpty();
        foreach ($modules as $name => ['path' => $path, 'tag' => $tag, 'slots' => $slots]) {
            expect(StarbaseBridgeTest::unmappedTokens((string) file_get_contents($path), $tag, StarbaseBridgeTest::defined($css, $tag, $slots)))
                ->toBe([], "{$name} reads unmapped tokens")
            ;
        }
    });

    test('the token scan reports an unmapped token, a fallback or another component\'s knob, and accepts the exceptions', function (): void {
        $code = <<<'JS'
            const css = `
                .a { color: var(--sb-text-1, #F3F4FA); inline-size: var(--sb-toast-width, 22rem); block-size: var(--sb-meter-height, 1rem); }
                .b { font-family: var(--sb-font-display, inherit); background: var(--sb-toggle-knob, var(--sb-text-1, #F3F4FA)); }
                .c { color: var(--sb-qr-color, #0B1224); border-color: var( --sb-ok-subtle ); }
                .d { color: var(--sb-toast-tone, #65BFFF); background: var(--sb-toast-forced, Canvas); fill: var(--sb-toast-variant, red); }
            `
            const read = ['--sb-chart-1', '--sb-brand-light', "--sb-nebula-glow", '--sb-toast-tone']
            JS;
        $css = (string) file_get_contents(StarbaseBridgeTest::CSS) . <<<'CSS'
            /* sb-toast */
            sb-toast, sb-toggle { --sb-toast-tone: var(--info); }
            sb-toast[variant='x'] { --sb-toast-variant: var(--danger); }
            @media (forced-colors: active) { sb-toast { --sb-toast-forced: Canvas; } }
            CSS;

        expect(StarbaseBridgeTest::unmappedTokens($code, 'sb-toast', StarbaseBridgeTest::defined($css, 'sb-toast')))
            ->toBe(['--sb-meter-height', '--sb-nebula-glow', '--sb-ok-subtle', '--sb-qr-color', '--sb-toast-forced', '--sb-toast-variant', '--sb-toggle-knob'])
            ->and(StarbaseBridgeTest::unmappedTokens($code, 'sb-meter', StarbaseBridgeTest::defined($css, 'sb-meter')))
            ->toBe(['--sb-nebula-glow', '--sb-ok-subtle', '--sb-qr-color', '--sb-toast-forced', '--sb-toast-tone', '--sb-toast-variant', '--sb-toast-width', '--sb-toggle-knob'])
        ;
    });

    test('a host rule defines a token only at its top level and only on a component without slots', function (): void {
        $code = '.a { color: var(--sb-toast-after, red); outline-color: var(--sb-toast-nested, red); caret-color: var(--sb-toast-hover, red); }';
        $css = <<<'CSS'
            sb-toast {
                @media (forced-colors: active) { --sb-toast-nested: Canvas; }
                &:hover { --sb-toast-hover: var(--info); }
                --sb-toast-after: var(--info)
            }
            CSS;

        expect(StarbaseBridgeTest::defined($css, 'sb-toast'))->toBe(['--sb-toast-after' => 'var(--info)'])
            ->and(StarbaseBridgeTest::unmappedTokens($code, 'sb-toast', StarbaseBridgeTest::defined($css, 'sb-toast')))
            ->toBe(['--sb-toast-hover', '--sb-toast-nested'])
            ->and(StarbaseBridgeTest::unmappedTokens($code, 'sb-toast', StarbaseBridgeTest::defined($css, 'sb-toast', true)))
            ->toBe(['--sb-toast-after', '--sb-toast-hover', '--sb-toast-nested'])
        ;
    });

    test('no vendored module has a pixel trait that is not reviewed', function (): void {
        $modules = StarbaseBridgeTest::modules();
        $found = [];

        expect($modules)->not->toBeEmpty();
        foreach ($modules as $name => ['path' => $path]) {
            $module = (string) preg_replace('/@[^\/]+/', '', $name, 1);
            foreach (StarbaseBridgeTest::pixelTraits((string) file_get_contents($path)) as $trait => $lines) {
                $found[$module][$trait] = true;
                expect(StarbaseBridgeTest::PIXEL_ALLOW[$module][$trait] ?? null)
                    ->not->toBeNull("{$name}: {$trait} on line " . implode(', ', $lines) . ' is not on PIXEL_ALLOW')
                ;
            }
        }
        foreach (StarbaseBridgeTest::PIXEL_ALLOW as $module => $traits) {
            foreach (array_keys($traits) as $trait) {
                expect(isset($found[$module][$trait]))->toBeTrue("PIXEL_ALLOW lists {$module} {$trait}, which no longer occurs");
            }
        }
    });

    test('the pixel scan finds each trait and passes corners that follow --sb-notch', function (): void {
        $code = <<<'JS'
            const notch = (p) => `polygon(${p} 0, calc(100% - ${p}) 0, 100% ${p}, 0 ${p})`
            const css = `
            :host { --_notch: var(--sb-notch, 1); --_n: calc(2px * var(--_notch)); --_u: 4px; --_m: calc(12px - var(--_n)); }
            .caret { clip-path: polygon(0 0, 8px 0, 8px 2px, 6px 2px, 0 2px); transition: rotate 120ms steps(2, end); }
            .light { clip-path: ${notch('3px')}; }
            canvas { image-rendering: pixelated; }
            img { image-rendering: crisp-edges; }
            .label { text-transform: uppercase; }
            .plate { clip-path: ${notch('var(--_n)')}; }
            .dot { clip-path: ${notch('calc(var(--_u) * var(--_notch))')}; }
            .status { clip-path: polygon(var(--_n) 0, var(--_m) 0, 12px var(--_n), 0 var(--_m)); }
            .knob { clip-path: polygon(var(--_u) 0, 100% var(--_u), 0 100%); }
            .tip { clip-path: polygon(0 0, 100% 0, 50% 100%); }
            .spin { animation: spin 0.6s steps(calc(4 + 996 * (1 - var(--_notch)))) infinite; }
            `
            const star = '<svg viewBox="0 0 7 7" shape-rendering="crispEdges"></svg>'
            JS;

        expect(StarbaseBridgeTest::pixelTraits($code))->toBe([
            'pixelated' => [6],
            'crisp edges' => [7, 16],
            'uppercase' => [8],
            'steps()' => [4],
            'fixed polygon' => [4, 5, 12],
        ]);
    });

    test('a variable follows --sb-notch only when every declaration of it does', function (): void {
        $code = <<<'JS'
            const notch = (p) => `polygon(${p} 0, 100% ${p}, 0 100%)`
            const css = `
            :host { --_n: 3px; --_a: var(--_b); --_b: var(--_a); }
            .plate { clip-path: polygon(var(--_n) 0, 100% var(--_n), 0 100%); }
            .light { clip-path: ${notch('var(--_n)')}; }
            .a { --_k: calc(2px * var(--sb-notch, 1)); } .b { --_k: 3px; }
            .c { clip-path: polygon(var(--_k) 0, 100% var(--_k), 0 100%); }
            .d { clip-path: polygon(var(--_a) 0, 4px 0, 0 100%); }
            `
            JS;

        expect(StarbaseBridgeTest::pixelTraits($code))->toBe(['fixed polygon' => [4, 5, 7, 8]]);
    });
});
