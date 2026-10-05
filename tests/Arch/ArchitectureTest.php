<?php

declare(strict_types=1);

// Strict types enforcement
arch('common classes use strict types')
    ->expect('mbolli\nfsen_ng\common')
    ->toUseStrictTypes()
;

arch('datasources use strict types')
    ->expect('mbolli\nfsen_ng\datasources')
    ->toUseStrictTypes()
;

arch('processors use strict types')
    ->expect('mbolli\nfsen_ng\processor')
    ->toUseStrictTypes()
;

arch('store classes use strict types')
    ->expect('mbolli\nfsen_ng\store')
    ->toUseStrictTypes()
;

arch('pages use strict types')
    ->expect('mbolli\nfsen_ng\pages')
    ->toUseStrictTypes()
;

// Interface implementation
arch('Rrd implements Datasource interface')
    ->expect('mbolli\nfsen_ng\datasources\Rrd')
    ->toImplement('mbolli\nfsen_ng\datasources\Datasource')
;

arch('VictoriaMetrics implements Datasource interface')
    ->expect('mbolli\nfsen_ng\datasources\VictoriaMetrics')
    ->toImplement('mbolli\nfsen_ng\datasources\Datasource')
;

arch('Nfdump implements Processor interface')
    ->expect('mbolli\nfsen_ng\processor\Nfdump')
    ->toImplement('mbolli\nfsen_ng\processor\Processor')
;

// Class design
arch('Nfdump is not abstract')
    ->expect('mbolli\nfsen_ng\processor\Nfdump')
    ->not->toBeAbstract()
;

arch('Config is abstract')
    ->expect('mbolli\nfsen_ng\common\Config')
    ->toBeAbstract()
;

arch('Datasource is an interface')
    ->expect('mbolli\nfsen_ng\datasources\Datasource')
    ->toBeInterface()
;

arch('Processor is an interface')
    ->expect('mbolli\nfsen_ng\processor\Processor')
    ->toBeInterface()
;

// Forbidden functions in library code
arch('no die or exit in common code')
    ->expect('mbolli\nfsen_ng\common')
    ->not->toUse(['die', 'exit'])
;

arch('no die or exit in datasources')
    ->expect('mbolli\nfsen_ng\datasources')
    ->not->toUse(['die', 'exit'])
;

arch('no die or exit in processors')
    ->expect('mbolli\nfsen_ng\processor')
    ->not->toUse(['die', 'exit'])
;

arch('no die or exit in the store')
    ->expect('mbolli\nfsen_ng\store')
    ->not->toUse(['die', 'exit'])
;

arch('no die or exit in pages')
    ->expect('mbolli\nfsen_ng\pages')
    ->not->toUse(['die', 'exit'])
;

// Dependency rules: actions and pages sit on top; nothing below them reaches up.
foreach (['query', 'store', 'common', 'datasources', 'processor'] as $layer) {
    arch("{$layer} does not use actions or pages")
        ->expect("mbolli\\nfsen_ng\\{$layer}")
        ->not->toUse(['mbolli\nfsen_ng\actions', 'mbolli\nfsen_ng\pages'])
    ;
}

// Any arch layer holding mcp classes autoloads the class "mbolli\nfsen_ng\mcp", which is the
// stdio server script backend/mcp.php, so the mcp rules resolve names from the tokens instead.

/**
 * Fully qualified names a PHP file refers to: every import (group imports expanded) and every
 * qualified, relative or fully qualified name, resolved against the namespace and the imports.
 *
 * @return list<string>
 */
function architectureTestReferences(string $code): array {
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        static fn (PhpToken $t): bool => !$t->isIgnorable(),
    ));
    $names = [];
    $namespace = '';
    $aliases = [];
    $depth = 0;
    $importDepth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            ++$depth;
        } elseif ($token->text === '}') {
            --$depth;
        } elseif ($token->is(T_NAMESPACE)) {
            $next = $tokens[$i + 1] ?? null;
            $namespace = $next?->is([T_STRING, T_NAME_QUALIFIED]) ? $next->text : '';
            $i += $namespace === '' ? 0 : 1;
            $aliases = [];
            $importDepth = ($tokens[$i + 1]->text ?? '') === '{' ? $depth + 1 : $depth;
        } elseif ($token->is(T_USE) && $depth === $importDepth && ($tokens[$i + 1]->text ?? '') !== '(') {
            $imports = [];
            $prefix = '';
            $alias = false;
            for (++$i; $i < $count && $tokens[$i]->text !== ';'; ++$i) {
                $part = $tokens[$i];
                if ($part->is(T_NS_SEPARATOR)) {
                    $prefix = array_pop($imports)[0] . '\\';
                } elseif ($part->is(T_AS)) {
                    $alias = true;
                } elseif ($part->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    if ($alias) {
                        $imports[array_key_last($imports)][1] = $part->text;
                        $alias = false;
                    } else {
                        $name = $prefix . ltrim($part->text, '\\');
                        $imports[] = [$name, substr((string) strrchr('\\' . $name, '\\'), 1)];
                    }
                }
            }
            foreach ($imports as [$name, $as]) {
                $names[] = $name;
                $aliases[strtolower($as)] = $name;
            }
        } elseif ($token->is(T_NAME_FULLY_QUALIFIED)) {
            $names[] = ltrim($token->text, '\\');
        } elseif ($token->is(T_NAME_RELATIVE)) {
            $names[] = ltrim($namespace . '\\' . substr($token->text, strlen('namespace\\')), '\\');
        } elseif ($token->is(T_NAME_QUALIFIED)) {
            [$first, $rest] = explode('\\', $token->text, 2);
            $names[] = isset($aliases[strtolower($first)])
                ? $aliases[strtolower($first)] . '\\' . $rest
                : ltrim($namespace . '\\' . $token->text, '\\');
        }
    }

    return $names;
}

/**
 * "<name> in <file>" for every reference in the PHP files at the given paths.
 *
 * @return list<string>
 */
function architectureTestReferencesIn(string ...$paths): array {
    $references = [];
    foreach ($paths as $path) {
        $files = is_dir($path)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
            : [new SplFileInfo($path)];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                foreach (architectureTestReferences((string) file_get_contents($file->getPathname())) as $name) {
                    $references[] = $name . ' in ' . $file->getFilename();
                }
            }
        }
    }

    return $references;
}

test('the reference scan resolves imports, groups and relative names', function (): void {
    $code = <<<'PHP'
        <?php
        namespace mbolli\nfsen_ng\mcp;

        use mbolli\nfsen_ng\actions;
        use mbolli\nfsen_ng\{pages\OverviewPage, common\Config as Cfg};
        use function mbolli\nfsen_ng\query\helper;
        use mbolli\nfsen_ng;

        $f = function () use ($x) { return actions\UtilityActions::class; };
        nfsen_ng\store\Database::shared();
        namespace\Guard::check(Cfg::$settings);
        \mbolli\nfsen_ng\processor\Nfdump::run();
        final class A { use Tool\Helper; }
        PHP;

    expect(architectureTestReferences($code))->toBe([
        'mbolli\nfsen_ng\actions',
        'mbolli\nfsen_ng\pages\OverviewPage',
        'mbolli\nfsen_ng\common\Config',
        'mbolli\nfsen_ng\query\helper',
        'mbolli\nfsen_ng',
        'mbolli\nfsen_ng\actions\UtilityActions',
        'mbolli\nfsen_ng\store\Database',
        'mbolli\nfsen_ng\mcp\Guard',
        'mbolli\nfsen_ng\processor\Nfdump',
        'mbolli\nfsen_ng\mcp\Tool\Helper',
    ]);
});

test('mcp does not use actions or pages', function (): void {
    $backend = dirname(__DIR__, 2) . '/backend';
    $references = architectureTestReferencesIn($backend . '/mcp', $backend . '/mcp.php');

    expect($references)->toContain('mbolli\nfsen_ng\mcp\ToolRegistry in mcp.php')
        ->and(preg_grep('/^mbolli\\\\nfsen_ng\\\\(actions|pages)(\\\\| )/i', $references))->toBe([])
    ;
});

test('store does not use mcp', function (): void {
    $references = architectureTestReferencesIn(dirname(__DIR__, 2) . '/backend/store');

    expect($references)->toContain('mbolli\nfsen_ng\common\Config in Database.php')
        ->and(preg_grep('/^mbolli\\\\nfsen_ng\\\\mcp(\\\\| )/i', $references))->toBe([])
    ;
});
