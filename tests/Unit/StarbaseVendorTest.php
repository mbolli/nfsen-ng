<?php

declare(strict_types=1);

/**
 * The offline checks of scripts/starbase-vendor.mjs (ROCKET-SPEC 3.7), in PHP.
 */
final class StarbaseVendorTest {
    public const string MIN_FORMAT = 'min2';
    public const string DIR = __DIR__ . '/../../frontend/js/starbase';
    public const string WALK = __DIR__ . '/../Support/starbase-walk';
    public const string BUNDLE = __DIR__ . '/../../vendor/mbolli/php-via/public/datastar-rocket.js';

    /** Starbase's slugRe and tagRe (internal/catalog/catalog.go). */
    public const string SLUG_RE = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';
    public const string TAG_RE = '/^sb-[a-z0-9]+(-[a-z0-9]+)*$/';

    private const string COMMENT = '/\*(?:[^*]|\*(?!/))*\*/';
    private const string WS = '(?:\s|' . self::COMMENT . '|//[^\n]*(?![^\n]))*';

    /** Starbase's specifier patterns (internal/catalog/imports.go), widened to comments; groups 1 and 3 are the quotes. */
    private const array IMPORT_RES = [
        '~(?:^|[;\s})/])import' . self::WS . '(?:[\w$*{}](?:[\w$*{}\s,]|' . self::COMMENT . ')*?from' . self::WS . ')?([\'"])([^\'"\n]+)([\'"])~m',
        '~(?:^|[;\s})/])export' . self::WS . '(?:\*|\{[^}]*\})' . self::WS . '(?:as\s+[\w$]+' . self::WS . ')?from' . self::WS . '([\'"])([^\'"\n]+)([\'"])~m',
        '~\bimport' . self::WS . '\(' . self::WS . '([\'"])([^\'"\n]+)([\'"])' . self::WS . '\)~',
    ];

    /** An import() whose argument is not one string literal. */
    private const string COMPUTED_IMPORT_RE = '~(?<![\w$.])import' . self::WS . '\((?!\s*([\'"])[^\'"\n]+\1\s*\))~';

    /** @return list<string> directory entries sorted by name, as Go's fs.ReadDir returns them */
    public static function entries(string $dir): array {
        $names = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        usort($names, strcmp(...));

        return $names;
    }

    /**
     * Files `go:embed` takes from one folder in fs.WalkDir order: names directly in the folder
     * always, below that no names starting with '.' or '_'.
     *
     * @return list<string>
     */
    public static function embeddedFiles(string $dir, string $rel = ''): array {
        $out = [];
        foreach (self::entries($dir) as $name) {
            if ($rel !== '' && ($name[0] === '.' || $name[0] === '_')) {
                continue;
            }
            $r = $rel === '' ? $name : $rel . '/' . $name;
            if (is_dir($dir . '/' . $name)) {
                array_push($out, ...self::embeddedFiles($dir . '/' . $name, $r));
            } elseif (is_file($dir . '/' . $name)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** @return list<string> every file below $dir, relative, sorted */
    public static function allFiles(string $dir, string $rel = ''): array {
        $out = [];
        foreach (self::entries($dir) as $name) {
            $r = $rel === '' ? $name : $rel . '/' . $name;
            if (is_dir($dir . '/' . $name)) {
                array_push($out, ...self::allFiles($dir . '/' . $name, $r));
            } else {
                $out[] = $r;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    public static function version(string $dir, string $slug): string {
        $h = hash_init('sha256');
        hash_update($h, self::MIN_FORMAT);
        foreach (self::embeddedFiles($dir) as $rel) {
            hash_update($h, $slug . '/' . $rel);
            hash_update($h, (string) file_get_contents($dir . '/' . $rel));
        }

        return substr(hash_final($h), 0, 12);
    }

    public static function sri(string $file): string {
        return 'sha384-' . base64_encode(hash_file('sha384', $file, true) ?: '');
    }

    /** @return list<string> */
    public static function imports(string $code): array {
        $out = [];
        foreach (self::IMPORT_RES as $re) {
            preg_match_all($re, $code, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                if ($m[1] === $m[3] && !in_array($m[2], $out, true)) {
                    $out[] = $m[2];
                }
            }
        }

        return $out;
    }

    /** True when a relative specifier from $rel stays inside the folder. */
    public static function staysInside(string $rel, string $spec): bool {
        $parts = [];
        $dir = dirname($rel);
        foreach (explode('/', ($dir === '.' ? '' : $dir . '/') . $spec) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                if ($parts === []) {
                    return false;
                }
                array_pop($parts);
            } else {
                $parts[] = $p;
            }
        }

        return true;
    }

    /** The first line of a Datastar bundle without '// ', or null when the file is missing. */
    public static function banner(string $bundle = self::BUNDLE): ?string {
        if (!is_file($bundle)) {
            return null;
        }

        return preg_replace('~^// ~', '', explode("\n", (string) file_get_contents($bundle), 2)[0]);
    }

    /**
     * Where the engine the lock expects, by banner and bytes, differs from the bundle php-via serves. Objects stay
     * objects, so a `"datastarPatches": []` fails here as it does in the script.
     *
     * @return list<string>
     */
    public static function engineProblems(string $json, string $bundle = self::BUNDLE): array {
        $lock = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        if (!$lock instanceof stdClass) {
            return ['starbase.lock.json is not an object'];
        }
        $problems = [];
        $shipped = self::banner($bundle);
        $datastar = $lock->datastar ?? null;
        if ($shipped === null) {
            $problems[] = 'vendor/mbolli/php-via/public/datastar-rocket.js is missing';
        } elseif (is_string($datastar) && $datastar !== $shipped) {
            $problems[] = "lock: datastar is '{$datastar}', vendor/mbolli/php-via/public/datastar-rocket.js is '{$shipped}'";
        }
        if (!($lock->datastarPatches ?? null) instanceof stdClass) {
            $problems[] = 'lock: datastarPatches is missing';
        }
        $sha = $lock->datastarSha256 ?? null;
        if (!property_exists($lock, 'datastarSha256')) {
            $problems[] = 'lock: datastarSha256 is missing, pull records it';
        } elseif (!is_string($sha) || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            $problems[] = 'lock: datastarSha256 is not 64 hex characters';
        } elseif ($shipped !== null && ($ours = (string) hash_file('sha256', $bundle)) !== $sha) {
            $problems[] = 'lock: datastarSha256 is ' . substr($sha, 0, 12) . ', vendor/mbolli/php-via/public/datastar-rocket.js hashes to ' . substr($ours, 0, 12);
        }

        return $problems;
    }

    /** @return list<string> the problems check() reports for a vendored directory */
    public static function problems(string $dir, string $bundle = self::BUNDLE): array {
        $lockFile = $dir . '/starbase.lock.json';
        if (!is_file($lockFile)) {
            return ['starbase.lock.json is missing'];
        }

        $json = (string) file_get_contents($lockFile);

        try {
            $lock = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['starbase.lock.json: ' . $e->getMessage()];
        }
        if (!is_array($lock)) {
            return ['starbase.lock.json is not an object'];
        }
        $problems = [];
        foreach (['repository', 'commit', 'describe', 'catalog', 'minFormat', 'datastar', 'license'] as $field) {
            if (!is_string($lock[$field] ?? null) || $lock[$field] === '') {
                $problems[] = "lock: {$field} is missing";
            }
        }
        if (($lock['minFormat'] ?? null) !== self::MIN_FORMAT) {
            $problems[] = 'lock: minFormat differs from ' . self::MIN_FORMAT;
        }
        if (is_string($lock['commit'] ?? null) && preg_match('/^[0-9a-f]{40}$/', $lock['commit']) !== 1) {
            $problems[] = 'lock: commit is not a full sha';
        }
        if (is_string($lock['catalog'] ?? null) && preg_match('/^[0-9a-f]{12}$/', $lock['catalog']) !== 1) {
            $problems[] = 'lock: catalog is not 12 hex characters';
        }
        $components = is_array($lock['components'] ?? null) ? $lock['components'] : [];
        if ($components === []) {
            $problems[] = 'lock: no components';
        }
        array_push($problems, ...self::engineProblems($json, $bundle));

        if (!is_file($dir . '/LICENSE')) {
            $problems[] = 'LICENSE is missing';
        } elseif (self::sri($dir . '/LICENSE') !== ($lock['license'] ?? null)) {
            $problems[] = 'LICENSE: sha384 differs from the lock';
        }

        $folders = [];
        foreach (self::entries($dir) as $name) {
            if (!is_dir($dir . '/' . $name)) {
                if ($name !== 'LICENSE' && $name !== 'starbase.lock.json') {
                    $problems[] = "unexpected file {$name}";
                }

                continue;
            }
            if (preg_match('/^([a-z][a-z0-9]*(?:-[a-z0-9]+)*)@([0-9a-f]{12})$/', $name, $m) !== 1) {
                $problems[] = "unexpected folder {$name}";

                continue;
            }
            if (isset($folders[$m[1]])) {
                $problems[] = "two versions of {$m[1]}";
            }
            $folders[$m[1]] = $m[2];
            if (!isset($components[$m[1]])) {
                $problems[] = "folder {$name} has no lock entry";
            }
        }

        foreach ($components as $slug => $c) {
            $slug = (string) $slug;
            if (preg_match(self::SLUG_RE, $slug) !== 1) {
                $problems[] = "lock: bad slug '{$slug}'";

                continue;
            }
            $version = is_array($c) ? ($c['version'] ?? null) : null;
            if (!is_string($version) || preg_match('/^[0-9a-f]{12}$/', $version) !== 1) {
                $problems[] = "{$slug}: bad version in the lock";

                continue;
            }
            $name = $slug . '@' . $version;
            $folder = $dir . '/' . $name;
            if (!is_dir($folder)) {
                $problems[] = "{$slug}: folder {$name} is missing";

                continue;
            }
            $files = is_array($c['files'] ?? null) ? $c['files'] : [];
            if (!is_string($c['tag'] ?? null) || preg_match(self::TAG_RE, $c['tag']) !== 1) {
                $problems[] = "{$slug}: bad tag in the lock";
            }
            if (!is_string($c['entry'] ?? null) || preg_match('/^[\w.-]+\.m?js$/', $c['entry']) !== 1 || !isset($files[$c['entry']])) {
                $problems[] = "{$slug}: entry is not one of its files";
            }
            if (!is_bool($c['load'] ?? null)) {
                $problems[] = "{$slug}: load must be true or false";
            }
            $onDisk = self::allFiles($folder);
            foreach ($onDisk as $rel) {
                if (!isset($files[$rel])) {
                    $problems[] = "{$name}/{$rel}: extra file";
                } elseif (self::sri($folder . '/' . $rel) !== $files[$rel]) {
                    $problems[] = "{$name}/{$rel}: sha384 differs from the lock";
                }
            }
            foreach (array_keys($files) as $rel) {
                if (!in_array((string) $rel, $onDisk, true)) {
                    $problems[] = "{$name}/{$rel}: missing";
                }
            }
            if (self::version($folder, $slug) !== $version) {
                $problems[] = "{$name}: its files hash to another version";
            }
            foreach ($onDisk as $rel) {
                if (preg_match('/\.m?js$/', $rel) !== 1) {
                    continue;
                }
                $code = (string) file_get_contents($folder . '/' . $rel);
                if (preg_match(self::COMPUTED_IMPORT_RE, $code) === 1) {
                    $problems[] = "{$name}/{$rel} has an import() with a computed specifier";
                }
                foreach (self::imports($code) as $spec) {
                    if ($spec === 'datastar') {
                        continue;
                    }
                    if (strpbrk($spec, '%\\') !== false) {
                        $problems[] = "{$name}/{$rel} imports '{$spec}', with '%' or a backslash";
                    } elseif (!str_starts_with($spec, './') && !str_starts_with($spec, '../')) {
                        $problems[] = "{$name}/{$rel} imports '{$spec}'";
                    } elseif (!self::staysInside($rel, $spec)) {
                        $problems[] = "{$name}/{$rel} imports '{$spec}', outside its folder";
                    }
                }
            }
        }

        return $problems;
    }

    public static function copyTree(string $from, string $to): void {
        mkdir($to, 0o777, true);
        foreach (self::entries($from) as $name) {
            if (is_dir($from . '/' . $name)) {
                self::copyTree($from . '/' . $name, $to . '/' . $name);
            } else {
                copy($from . '/' . $name, $to . '/' . $name);
            }
        }
    }

    public static function removeTree(string $dir): void {
        foreach (self::entries($dir) as $name) {
            is_dir($dir . '/' . $name) ? self::removeTree($dir . '/' . $name) : unlink($dir . '/' . $name);
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    public static function lock(string $dir = self::DIR): array {
        return json_decode((string) file_get_contents($dir . '/starbase.lock.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string, 1: string} a temporary copy of the vendored directory and its first component folder */
    public static function tempCopy(): array {
        $tmp = sys_get_temp_dir() . '/starbase-vendor-' . bin2hex(random_bytes(6));
        self::copyTree(self::DIR, $tmp);
        $lock = self::lock($tmp);
        $slug = array_key_first($lock['components']);

        return [$tmp, $tmp . '/' . $slug . '@' . $lock['components'][$slug]['version']];
    }
}

describe('Starbase vendoring', function (): void {
    afterEach(function (): void {
        foreach (['tmp', 'engine'] as $dir) {
            if (isset($this->{$dir}) && is_dir($this->{$dir})) {
                StarbaseVendorTest::removeTree($this->{$dir});
            }
        }
    });

    test('the lock pins at least one component and names its engine', function (): void {
        $lock = StarbaseVendorTest::lock();

        expect($lock['components'])->toBeArray()->not->toBeEmpty()
            ->and($lock['commit'])->toMatch('/^[0-9a-f]{40}$/')
            ->and($lock['catalog'])->toMatch('/^[0-9a-f]{12}$/')
            ->and($lock['minFormat'])->toBe(StarbaseVendorTest::MIN_FORMAT)
            ->and($lock['datastar'])->toStartWith('Datastar v')
        ;
        foreach ($lock['components'] as $slug => $c) {
            expect($slug)->toMatch(StarbaseVendorTest::SLUG_RE)
                ->and($c['tag'])->toMatch(StarbaseVendorTest::TAG_RE)
                ->and($c['entry'])->toBe($slug . '.js')
                ->and($c['files'])->toHaveKey($c['entry'])
            ;
        }
    });

    test('the lock names the engine php-via serves, by banner and bytes, and records its patch set', function (): void {
        $lock = StarbaseVendorTest::lock();

        expect($lock['datastar'])->toBe(StarbaseVendorTest::banner())
            ->and($lock['datastarSha256'] ?? null)->toBe(hash_file('sha256', StarbaseVendorTest::BUNDLE))
            ->and($lock['datastarPatches'])->toBeArray()
            ->and(array_filter($lock['datastarPatches'], static fn ($v, $k): bool => !str_ends_with((string) $k, '.patch') || !str_starts_with((string) $v, 'sha384-'), ARRAY_FILTER_USE_BOTH))->toBe([])
            ->and(StarbaseVendorTest::engineProblems((string) file_get_contents(StarbaseVendorTest::DIR . '/starbase.lock.json')))->toBe([])
        ;
    });

    test('the vendored folders pass every offline check', function (): void {
        expect(StarbaseVendorTest::problems(StarbaseVendorTest::DIR))->toBe([]);
    });

    test('the folder name is the version recomputed from its bytes', function (): void {
        foreach (StarbaseVendorTest::lock()['components'] as $slug => $c) {
            $folder = StarbaseVendorTest::DIR . '/' . $slug . '@' . $c['version'];
            expect(StarbaseVendorTest::version($folder, $slug))->toBe($c['version'])
                ->and(StarbaseVendorTest::allFiles($folder))->toBe(array_keys($c['files']))
            ;
        }
    });

    test('the walk follows go:embed and fs.WalkDir', function (string $slug, array $files, string $version): void {
        $dir = StarbaseVendorTest::WALK . '/' . $slug;

        expect(StarbaseVendorTest::embeddedFiles($dir))->toBe($files)
            ->and(StarbaseVendorTest::version($dir, $slug))->toBe($version)
        ;
    })->with([
        // Versions computed by a Go program embedding these folders with `//go:embed */*`.
        'dot and underscore names directly in the folder' => ['top-files', ['.hidden', 'README.md', '_draft.js', 'top-files.js'], '198864eb3b0c'],
        'dot and underscore names below vendor/' => ['nested-skip', ['nested-skip.js', 'vendor/lib.js', 'vendor/sub/deep.js', 'vendor.json'], '920222058339'],
    ]);

    test('one changed byte fails the check', function (): void {
        [$this->tmp, $folder] = StarbaseVendorTest::tempCopy();
        $lock = StarbaseVendorTest::lock($this->tmp);
        $entry = $folder . '/' . $lock['components'][array_key_first($lock['components'])]['entry'];
        $bytes = (string) file_get_contents($entry);
        $bytes[0] = $bytes[0] === 'a' ? 'b' : 'a';
        file_put_contents($entry, $bytes);

        expect(StarbaseVendorTest::problems($this->tmp))
            ->toContain(basename($folder) . '/' . basename($entry) . ': sha384 differs from the lock')
            ->toContain(basename($folder) . ': its files hash to another version')
        ;
    });

    test('a missing, extra or unlisted file fails the check', function (): void {
        [$this->tmp, $folder] = StarbaseVendorTest::tempCopy();
        unlink($folder . '/README.md');
        file_put_contents($folder . '/extra.txt', 'x');
        mkdir($this->tmp . '/tooltip@000000000000');

        expect(StarbaseVendorTest::problems($this->tmp))
            ->toContain(basename($folder) . '/README.md: missing')
            ->toContain(basename($folder) . '/extra.txt: extra file')
            ->toContain('folder tooltip@000000000000 has no lock entry')
        ;
    });

    test('a second version of a slug, or a lock entry without a folder, fails', function (): void {
        [$this->tmp, $folder] = StarbaseVendorTest::tempCopy();
        $slug = explode('@', basename($folder))[0];
        StarbaseVendorTest::copyTree($folder, $this->tmp . '/' . $slug . '@ffffffffffff');
        expect(StarbaseVendorTest::problems($this->tmp))->toContain("two versions of {$slug}");

        StarbaseVendorTest::removeTree($folder);
        expect(StarbaseVendorTest::problems($this->tmp))->toContain("{$slug}: folder " . basename($folder) . ' is missing');
    });

    test('a missing or changed LICENSE fails', function (): void {
        [$this->tmp] = StarbaseVendorTest::tempCopy();
        file_put_contents($this->tmp . '/LICENSE', "\n", FILE_APPEND);
        expect(StarbaseVendorTest::problems($this->tmp))->toContain('LICENSE: sha384 differs from the lock');

        unlink($this->tmp . '/LICENSE');
        expect(StarbaseVendorTest::problems($this->tmp))->toContain('LICENSE is missing');
    });

    test('an import other than datastar or a file in the folder fails, in any module', function (): void {
        [$this->tmp, $folder] = StarbaseVendorTest::tempCopy();
        mkdir($folder . '/vendor');
        $module = "import 'datastar'\nimport x from './vendor/ok.js'\nimport y from '../../escape.js'\n"
            . "export * from 'lit'\nconst z = await import(\"https://cdn.example/z.js\")\n"
            . "const ok = () => import( './vendor/ok.js' )\n"
            . "import a from './%2e%2e/%2e%2e/app.js'\nimport b from './..\\..\\x.js'\n"
            . "/* c */import 'https://c.example/x.js'\nimport/**/'https://d.example/x.js'\n"
            . "export/**/* from 'https://e.example/x.js'\nimport f /* g */ from // h\n'https://f.example/x.js'\n";
        file_put_contents($folder . '/vendor/ok.js', "export default import.meta.url\n");
        file_put_contents($folder . '/vendor/computed.js', 'const u = "z"; await import(`https://cdn.example/${u}.js`)' . "\n");
        file_put_contents($folder . '/vendor/commented.js', "const u = 'z'; await import/**/(u)\n");
        file_put_contents($folder . '/vendor/lib.mjs', $module);
        $name = basename($folder);

        expect(StarbaseVendorTest::problems($this->tmp))
            ->toContain("{$name}/vendor/lib.mjs imports '../../escape.js', outside its folder")
            ->toContain("{$name}/vendor/lib.mjs imports 'lit'")
            ->toContain("{$name}/vendor/lib.mjs imports 'https://cdn.example/z.js'")
            ->toContain("{$name}/vendor/lib.mjs imports './%2e%2e/%2e%2e/app.js', with '%' or a backslash")
            ->toContain("{$name}/vendor/lib.mjs imports './..\\..\\x.js', with '%' or a backslash")
            ->toContain("{$name}/vendor/lib.mjs imports 'https://c.example/x.js'")
            ->toContain("{$name}/vendor/lib.mjs imports 'https://d.example/x.js'")
            ->toContain("{$name}/vendor/lib.mjs imports 'https://e.example/x.js'")
            ->toContain("{$name}/vendor/lib.mjs imports 'https://f.example/x.js'")
            ->not->toContain("{$name}/vendor/lib.mjs imports 'datastar'")
            ->not->toContain("{$name}/vendor/lib.mjs imports './vendor/ok.js'")
            ->toContain("{$name}/vendor/computed.js has an import() with a computed specifier")
            ->toContain("{$name}/vendor/commented.js has an import() with a computed specifier")
            ->not->toContain("{$name}/vendor/lib.mjs has an import() with a computed specifier")
            ->not->toContain("{$name}/vendor/ok.js has an import() with a computed specifier")
        ;
    });

    test('a bad slug, tag or entry in the lock fails', function (): void {
        [$this->tmp, $folder] = StarbaseVendorTest::tempCopy();
        $lock = StarbaseVendorTest::lock($this->tmp);
        $slug = (string) array_key_first($lock['components']);
        $lock['components'][$slug]['tag'] = 'x-' . $slug;
        $lock['components'][$slug]['entry'] = '../' . $slug . '.js';
        $lock['components']['../escape'] = $lock['components'][$slug];
        $lock['components']['9lives'] = $lock['components'][$slug];
        file_put_contents($this->tmp . '/starbase.lock.json', json_encode($lock));

        expect(StarbaseVendorTest::problems($this->tmp))
            ->toContain("{$slug}: bad tag in the lock")
            ->toContain("{$slug}: entry is not one of its files")
            ->toContain("lock: bad slug '../escape'")
            ->toContain("lock: bad slug '9lives'")
            ->not->toContain('../escape: folder ../escape@' . $lock['components'][$slug]['version'] . ' is missing')
        ;
    });

    test('an edited datastar field fails the check', function (): void {
        [$this->tmp] = StarbaseVendorTest::tempCopy();
        $lock = StarbaseVendorTest::lock($this->tmp);
        $shipped = $lock['datastar'];
        $lock['datastar'] = 'Datastar v1.0.4 + Rocket beta.2';
        file_put_contents($this->tmp . '/starbase.lock.json', json_encode($lock));

        expect(StarbaseVendorTest::problems($this->tmp))
            ->toContain("lock: datastar is 'Datastar v1.0.4 + Rocket beta.2', vendor/mbolli/php-via/public/datastar-rocket.js is '{$shipped}'")
        ;
    });

    test('a bundle with another banner, or none, fails the check', function (): void {
        [$this->tmp] = StarbaseVendorTest::tempCopy();
        $this->engine = $this->tmp . '-engine';
        mkdir($this->engine);
        file_put_contents($this->engine . '/datastar-rocket.js', "// Datastar v1.0.5 + Rocket beta.3\nexport {};\n");
        $locked = StarbaseVendorTest::lock($this->tmp)['datastar'];

        expect(StarbaseVendorTest::problems($this->tmp, $this->engine . '/datastar-rocket.js'))
            ->toContain("lock: datastar is '{$locked}', vendor/mbolli/php-via/public/datastar-rocket.js is 'Datastar v1.0.5 + Rocket beta.3'")
            ->and(StarbaseVendorTest::problems($this->tmp, $this->engine . '/missing.js'))
            ->toContain('vendor/mbolli/php-via/public/datastar-rocket.js is missing')
        ;
    });

    test('a lock without datastarSha256, or whose patch set is not an object, fails; an empty patch set passes', function (): void {
        [$this->tmp] = StarbaseVendorTest::tempCopy();
        $lock = StarbaseVendorTest::lock($this->tmp);
        unset($lock['datastarSha256']);
        $lock['datastarPatches'] = [];
        file_put_contents($this->tmp . '/starbase.lock.json', json_encode($lock));

        expect(StarbaseVendorTest::problems($this->tmp))
            ->toContain('lock: datastarSha256 is missing, pull records it')
            ->toContain('lock: datastarPatches is missing')
        ;

        // An unpatched pin records no patches.
        $lock['datastarSha256'] = hash_file('sha256', StarbaseVendorTest::BUNDLE);
        $lock['datastarPatches'] = new stdClass();
        file_put_contents($this->tmp . '/starbase.lock.json', json_encode($lock));
        expect(StarbaseVendorTest::problems($this->tmp))->toBe([]);
    });

    test('the bundle bytes must hash to datastarSha256', function (): void {
        [$this->tmp] = StarbaseVendorTest::tempCopy();
        $this->engine = $this->tmp . '-engine';
        mkdir($this->engine);
        $lock = StarbaseVendorTest::lock($this->tmp);
        $sha = (string) hash_file('sha256', StarbaseVendorTest::BUNDLE);
        $lock['datastarSha256'] = $sha;
        file_put_contents($this->tmp . '/starbase.lock.json', json_encode($lock));
        expect(StarbaseVendorTest::problems($this->tmp))->toBe([]);

        $bundle = $this->engine . '/datastar-rocket.js';
        file_put_contents($bundle, (string) file_get_contents(StarbaseVendorTest::BUNDLE) . "\n");
        expect(StarbaseVendorTest::problems($this->tmp, $bundle))
            ->toBe(['lock: datastarSha256 is ' . substr($sha, 0, 12) . ', vendor/mbolli/php-via/public/datastar-rocket.js hashes to ' . substr((string) hash_file('sha256', $bundle), 0, 12)])
        ;

        $lock['datastarSha256'] = strtoupper($sha);
        file_put_contents($this->tmp . '/starbase.lock.json', json_encode($lock));
        expect(StarbaseVendorTest::problems($this->tmp))->toBe(['lock: datastarSha256 is not 64 hex characters']);
    });

    test('an empty lock fails instead of passing', function (): void {
        [$this->tmp, $folder] = StarbaseVendorTest::tempCopy();
        StarbaseVendorTest::removeTree($folder);
        $lock = StarbaseVendorTest::lock($this->tmp);
        $lock['components'] = [];
        file_put_contents($this->tmp . '/starbase.lock.json', json_encode($lock));

        expect(StarbaseVendorTest::problems($this->tmp))->toContain('lock: no components');
    });
});
