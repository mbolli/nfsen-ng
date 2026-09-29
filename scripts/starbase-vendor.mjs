#!/usr/bin/env node
// Vendors Starbase components into frontend/js/starbase/ and checks them offline.
// Usage and the algorithm: .redesign/ROCKET-SPEC.md, sections 3.1 to 3.7.
import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, posix, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DIR = join(ROOT, 'frontend', 'js', 'starbase');
const LOCK = join(DIR, 'starbase.lock.json');
const STAGE_PREFIX = '.starbase-stage-';
const MIN_FORMAT = 'min2';
const DEFAULT_REPOSITORY = 'https://github.com/zweiundeins/starbase.git';
// Starbase's slugRe and tagRe (internal/catalog/catalog.go).
const SLUG_RE = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
const TAG_RE = /^sb-[a-z0-9]+(?:-[a-z0-9]+)*$/;
const VERSION_RE = /^[0-9a-f]{12}$/;
const FOLDER_RE = /^([a-z][a-z0-9]*(?:-[a-z0-9]+)*)@([0-9a-f]{12})$/;

// Starbase's specifier patterns (internal/catalog/imports.go), widened so comments between tokens
// or before the statement cannot hide an import; group 1 and 3 are the quotes.
const COMMENT = String.raw`/\*(?:[^*]|\*(?!/))*\*/`;
const WS = String.raw`(?:\s|${COMMENT}|//[^\n]*(?![^\n]))*`;
const IMPORT_RES = [
    new RegExp(String.raw`(?:^|[;\s})/])import${WS}(?:[\w$*{}](?:[\w$*{}\s,]|${COMMENT})*?from${WS})?(['"])([^'"\n]+)(['"])`, 'gm'),
    new RegExp(String.raw`(?:^|[;\s})/])export${WS}(?:\*|\{[^}]*\})${WS}(?:as\s+[\w$]+${WS})?from${WS}(['"])([^'"\n]+)(['"])`, 'gm'),
    new RegExp(String.raw`\bimport${WS}\(${WS}(['"])([^'"\n]+)(['"])${WS}\)`, 'g'),
];
// A dynamic import whose argument is not one string literal could load any URL at runtime.
const COMPUTED_IMPORT_RE = new RegExp(String.raw`(?<![\w$.])import${WS}\((?!\s*(['"])[^'"\n]+\1\s*\))`);

class UsageError extends Error {}

const byName = (a, b) => (a < b ? -1 : a > b ? 1 : 0);
const sortedEntries = (dir) => readdirSync(dir, { withFileTypes: true }).sort((a, b) => byName(a.name, b.name));
const sri = (buf) => `sha384-${createHash('sha384').update(buf).digest('base64')}`;

// The files Starbase's embed pattern (components.go) takes from one folder, in fs.WalkDir order.
export function embeddedFiles(dir) {
    const out = [];
    const walk = (abs, rel, top) => {
        for (const e of sortedEntries(abs)) {
            // Names directly in the folder match the pattern; below that, Go skips '.' and '_' names.
            if (!top && (e.name.startsWith('.') || e.name.startsWith('_'))) continue;
            const r = rel === '' ? e.name : `${rel}/${e.name}`;
            if (e.isDirectory()) walk(join(abs, e.name), r, false);
            else if (e.isFile()) out.push(r);
        }
    };
    walk(dir, '', true);
    return out;
}

/** Every regular file below dir, relative, sorted. */
function allFiles(dir) {
    const out = [];
    const walk = (abs, rel) => {
        for (const e of sortedEntries(abs)) {
            const r = rel === '' ? e.name : `${rel}/${e.name}`;
            if (e.isDirectory()) walk(join(abs, e.name), r);
            else out.push(r);
        }
    };
    walk(dir, '');
    return out.sort(byName);
}

/** Starbase's component version (SB/internal/catalog/catalog.go, loadOne). */
export function componentVersion(dir, slug) {
    const h = createHash('sha256');
    h.update(MIN_FORMAT);
    for (const rel of embeddedFiles(dir)) {
        h.update(`${slug}/${rel}`);
        h.update(readFileSync(join(dir, rel)));
    }
    return h.digest('hex').slice(0, 12);
}

function catalogHash(versionsBySlug) {
    const h = createHash('sha256');
    for (const slug of Object.keys(versionsBySlug).sort(byName)) h.update(versionsBySlug[slug]);
    return h.digest('hex').slice(0, 12);
}

function importSpecifiers(code) {
    const out = [];
    for (const re of IMPORT_RES) {
        for (const m of code.matchAll(re)) {
            if (m[1] === m[3] && !out.includes(m[2])) out.push(m[2]);
        }
    }
    return out;
}

/** Problems with the imports of every .js/.mjs file of a vendored folder. */
function importProblems(folderDir, label) {
    const problems = [];
    for (const rel of allFiles(folderDir)) {
        if (!/\.m?js$/.test(rel)) continue;
        const code = readFileSync(join(folderDir, rel), 'utf8');
        if (COMPUTED_IMPORT_RE.test(code)) problems.push(`${label}/${rel} has an import() with a computed specifier`);
        for (const spec of importSpecifiers(code)) {
            if (spec === 'datastar') continue;
            // The browser reads %2e%2e as '..' and '\\' as '/' in an http URL.
            if (/[%\\]/.test(spec)) {
                problems.push(`${label}/${rel} imports '${spec}': no '%' or '\\' in a specifier`);
                continue;
            }
            if (!spec.startsWith('./') && !spec.startsWith('../')) {
                problems.push(`${label}/${rel} imports '${spec}': only 'datastar' and files in the folder`);
                continue;
            }
            const target = posix.normalize(posix.join(posix.dirname(rel), spec));
            if (target === '..' || target.startsWith('../')) {
                problems.push(`${label}/${rel} imports '${spec}', outside its folder`);
            }
        }
    }
    return problems;
}

function readLock() {
    if (!existsSync(LOCK)) return null;
    return JSON.parse(readFileSync(LOCK, 'utf8'));
}

function writeLock(lock, file = LOCK) {
    const components = {};
    for (const slug of Object.keys(lock.components).sort(byName)) {
        const c = lock.components[slug];
        const files = {};
        for (const f of Object.keys(c.files).sort(byName)) files[f] = c.files[f];
        components[slug] = { tag: c.tag, version: c.version, entry: c.entry, load: c.load === true, files };
    }
    const ordered = {
        repository: lock.repository,
        commit: lock.commit,
        describe: lock.describe,
        catalog: lock.catalog,
        minFormat: lock.minFormat,
        datastar: lock.datastar,
        datastarPatches: lock.datastarPatches ?? {},
        license: lock.license,
        components,
    };
    writeFileSync(file, `${JSON.stringify(ordered, null, 2)}\n`);
}

/** The given absolute paths that .gitignore would ignore, or throws when git fails. */
function ignoredPaths(paths) {
    if (paths.length === 0) return [];
    const input = paths.map((p) => relative(ROOT, p)).join('\n');
    const r = spawnSync('git', ['-C', ROOT, 'check-ignore', '--no-index', '--stdin'], { input, encoding: 'utf8' });
    if (r.status === 0) return r.stdout.trim().split('\n');
    if (r.status === 1) return [];
    throw new Error(`git check-ignore failed: ${(r.stderr || r.error?.message || '').trim()}`);
}

/** Every offline check of ROCKET-SPEC 3.7; returns the problems found. */
export function checkProblems(dir = DIR, { gitIgnore = true } = {}) {
    const problems = [];
    const lockPath = join(dir, 'starbase.lock.json');
    if (!existsSync(lockPath)) return [`${relative(ROOT, lockPath)} is missing`];
    let lock;
    try {
        lock = JSON.parse(readFileSync(lockPath, 'utf8'));
    } catch (e) {
        return [`${relative(ROOT, lockPath)}: ${e.message}`];
    }
    for (const field of ['repository', 'commit', 'describe', 'catalog', 'minFormat', 'datastar', 'license']) {
        if (typeof lock[field] !== 'string' || lock[field] === '') problems.push(`lock: ${field} is missing`);
    }
    if (lock.minFormat !== MIN_FORMAT) problems.push(`lock: minFormat is '${lock.minFormat}', this script hashes '${MIN_FORMAT}'`);
    if (typeof lock.commit === 'string' && !/^[0-9a-f]{40}$/.test(lock.commit)) problems.push('lock: commit is not a full sha');
    if (typeof lock.catalog === 'string' && !VERSION_RE.test(lock.catalog)) problems.push('lock: catalog is not 12 hex characters');
    const components = lock.components && typeof lock.components === 'object' ? lock.components : {};
    if (Object.keys(components).length === 0) problems.push('lock: no components');

    const licensePath = join(dir, 'LICENSE');
    if (!existsSync(licensePath)) problems.push('LICENSE is missing');
    else if (sri(readFileSync(licensePath)) !== lock.license) problems.push('LICENSE: sha384 differs from the lock');

    const folders = new Map();
    for (const e of sortedEntries(dir)) {
        if (!e.isDirectory()) {
            if (e.name !== 'LICENSE' && e.name !== 'starbase.lock.json') problems.push(`unexpected file ${e.name}`);
            continue;
        }
        const m = FOLDER_RE.exec(e.name);
        if (!m) {
            problems.push(`unexpected folder ${e.name}: not <slug>@<version>`);
            continue;
        }
        if (folders.has(m[1])) problems.push(`two versions of ${m[1]}: ${folders.get(m[1])} and ${m[2]}`);
        else folders.set(m[1], m[2]);
        if (!Object.hasOwn(components, m[1])) problems.push(`folder ${e.name} has no lock entry`);
    }

    const vendoredPaths = [];
    for (const [slug, c] of Object.entries(components)) {
        if (!SLUG_RE.test(slug)) {
            problems.push(`lock: bad slug '${slug}'`);
            continue;
        }
        if (typeof c?.version !== 'string' || !VERSION_RE.test(c.version)) {
            problems.push(`${slug}: bad version in the lock`);
            continue;
        }
        const name = `${slug}@${c.version}`;
        const folderDir = join(dir, name);
        if (!existsSync(folderDir) || !statSync(folderDir).isDirectory()) {
            problems.push(`${slug}: folder ${name} is missing`);
            continue;
        }
        if (typeof c.tag !== 'string' || !TAG_RE.test(c.tag)) problems.push(`${slug}: bad tag in the lock`);
        if (typeof c.load !== 'boolean') problems.push(`${slug}: load must be true or false`);
        const files = c.files && typeof c.files === 'object' ? c.files : {};
        if (typeof c.entry !== 'string' || !/^[\w.-]+\.m?js$/.test(c.entry) || !Object.hasOwn(files, c.entry)) {
            problems.push(`${slug}: entry '${c.entry}' is not one of its files`);
        }
        const onDisk = allFiles(folderDir);
        for (const rel of onDisk) {
            vendoredPaths.push(join(folderDir, rel));
            if (!Object.hasOwn(files, rel)) problems.push(`${name}/${rel}: extra file, not in the lock`);
            else if (sri(readFileSync(join(folderDir, rel))) !== files[rel]) problems.push(`${name}/${rel}: sha384 differs from the lock`);
        }
        for (const rel of Object.keys(files)) {
            if (!onDisk.includes(rel)) problems.push(`${name}/${rel}: missing`);
        }
        const version = componentVersion(folderDir, slug);
        if (version !== c.version) problems.push(`${name}: its files hash to version ${version}`);
        problems.push(...importProblems(folderDir, name));
    }

    if (gitIgnore) {
        for (const f of [licensePath, lockPath]) if (existsSync(f)) vendoredPaths.push(f);
        try {
            problems.push(...ignoredPaths(vendoredPaths).map((p) => `${p}: ignored by git (see .gitignore)`));
        } catch (e) {
            problems.push(e.message);
        }
    }
    return problems;
}

/** Stage folders a killed pull left next to DIR. */
function leftoverStages() {
    const parent = dirname(DIR);
    if (!existsSync(parent)) return [];
    return readdirSync(parent)
        .filter((e) => e.startsWith(STAGE_PREFIX))
        .map((e) => `${relative(ROOT, join(parent, e))}: left over from a pull that was killed, remove it`);
}

function check() {
    const problems = [...checkProblems(), ...leftoverStages()];
    if (problems.length > 0) {
        for (const p of problems) console.error(`FAIL ${p}`);
        return 1;
    }
    const lock = readLock();
    const list = Object.entries(lock.components).map(([s, c]) => `${s}@${c.version}${c.load ? ' (loaded)' : ''}`);
    console.log(`ok: ${list.join(', ')} from Starbase ${lock.describe}`);
    return 0;
}

function run(cmd, args, opts = {}) {
    try {
        return execFileSync(cmd, args, { stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 1 << 30, ...opts });
    } catch (e) {
        const why = (e.stderr?.toString() || e.message).trim().split('\n')[0];
        throw new UsageError(`${cmd} ${args.join(' ')}: ${why}`);
    }
}

const git = (from, ...args) => run('git', ['-C', from, ...args], { encoding: 'utf8' }).trim();

function tagOf(folderDir) {
    const manifest = join(folderDir, 'manifest.json');
    if (existsSync(manifest)) {
        const tag = JSON.parse(readFileSync(manifest, 'utf8')).tag;
        if (typeof tag === 'string') return tag;
    }
    const m = /^tag:\s*(\S+)\s*$/m.exec(readFileSync(join(folderDir, 'README.md'), 'utf8'));
    return m ? m[1] : '';
}

function manifestProps(file) {
    if (!existsSync(file)) return new Map();
    const props = JSON.parse(readFileSync(file, 'utf8')).props ?? [];
    return new Map(props.map((p) => [p.name, p]));
}

function propChanges(before, after) {
    const lines = [];
    for (const [name, p] of after) {
        if (!before.has(name)) lines.push(`  + ${name} (${p.type}, default ${JSON.stringify(p.default)})`);
        else if (JSON.stringify(before.get(name).default) !== JSON.stringify(p.default)) {
            lines.push(`  ~ ${name} default ${JSON.stringify(before.get(name).default)} is now ${JSON.stringify(p.default)}`);
        }
    }
    for (const name of before.keys()) if (!after.has(name)) lines.push(`  - ${name}`);
    return lines;
}

function pull(args) {
    const opts = { slugs: [] };
    for (let i = 0; i < args.length; i++) {
        if (args[i] === '--from') opts.from = args[++i];
        else if (args[i] === '--ref') opts.ref = args[++i];
        else opts.slugs.push(args[i]);
    }
    if (!opts.from || !opts.ref || opts.slugs.length === 0) throw new UsageError('pull needs --from <starbase clone> --ref <commit|tag> <slug> [...]');
    for (const s of opts.slugs) if (!SLUG_RE.test(s)) throw new UsageError(`bad slug '${s}'`);

    const from = resolve(opts.from);
    const commit = git(from, 'rev-parse', '--verify', `${opts.ref}^{commit}`);
    const describe = git(from, 'describe', '--tags', '--always', commit);
    const old = readLock();
    // Every locked component moves with the pin, so the lock always names one Starbase commit.
    const slugs = [...new Set([...opts.slugs, ...Object.keys(old?.components ?? {})])].sort(byName);

    const tmp = mkdtempSync(join(tmpdir(), 'starbase-vendor-'));
    let stage;
    const cleanup = () => {
        rmSync(tmp, { recursive: true, force: true });
        if (stage) rmSync(stage, { recursive: true, force: true });
    };
    // A listener stops SIGINT and SIGTERM from killing Node mid-copy, so finally always cleans up.
    const onSignal = (sig) => {
        cleanup();
        process.exit(sig === 'SIGINT' ? 130 : 143);
    };
    process.once('SIGINT', onSignal).once('SIGTERM', onSignal);
    try {
        const paths = ['components', 'LICENSE', 'static/vendor/datastar-rocket.js'];
        if (git(from, 'ls-tree', '-d', '--name-only', commit, 'patches/rocket') !== '') paths.push('patches/rocket');
        const archive = run('git', ['-C', from, 'archive', '--format=tar', commit, ...paths]);
        run('tar', ['-x', '-C', tmp], { input: archive });

        const datastar = readFileSync(join(tmp, 'static/vendor/datastar-rocket.js'), 'utf8').split('\n')[0].replace(/^\/\/ /, '');
        const datastarPatches = {};
        if (existsSync(join(tmp, 'patches/rocket'))) {
            for (const e of sortedEntries(join(tmp, 'patches/rocket'))) {
                if (e.isFile() && e.name.endsWith('.patch')) datastarPatches[e.name] = sri(readFileSync(join(tmp, 'patches/rocket', e.name)));
            }
        }

        // Starbase's catalog skips folders whose names start with '.' or '_' (catalog.go, Load).
        const versions = {};
        for (const e of sortedEntries(join(tmp, 'components'))) {
            if (!e.isDirectory() || e.name.startsWith('.') || e.name.startsWith('_')) continue;
            versions[e.name] = componentVersion(join(tmp, 'components', e.name), e.name);
        }
        for (const s of slugs) {
            if (!versions[s]) throw new UsageError(`Starbase ${describe} has no component '${s}'`);
            if (!existsSync(join(tmp, 'components', s, `${s}.js`))) throw new UsageError(`${s} has no ${s}.js`);
        }

        const lock = {
            repository: old?.repository ?? DEFAULT_REPOSITORY,
            commit,
            describe,
            catalog: catalogHash(versions),
            minFormat: MIN_FORMAT,
            datastar,
            datastarPatches,
            license: sri(readFileSync(join(tmp, 'LICENSE'))),
            components: {},
        };

        // Build the whole new tree next to DIR, check it, then move it into place.
        mkdirSync(DIR, { recursive: true });
        stage = mkdtempSync(join(dirname(DIR), STAGE_PREFIX));
        const report = [];
        const targets = [join(DIR, 'LICENSE'), LOCK];
        for (const slug of slugs) {
            const src = join(tmp, 'components', slug);
            const version = versions[slug];
            const name = `${slug}@${version}`;
            const prev = old?.components?.[slug];
            const before = prev ? manifestProps(join(DIR, `${slug}@${prev.version}`, 'manifest.json')) : new Map();
            const files = {};
            for (const rel of embeddedFiles(src)) {
                mkdirSync(dirname(join(stage, name, rel)), { recursive: true });
                cpSync(join(src, rel), join(stage, name, rel));
                files[rel] = sri(readFileSync(join(src, rel)));
                targets.push(join(DIR, name, rel));
            }
            const tag = tagOf(src);
            lock.components[slug] = { tag, version, entry: `${slug}.js`, load: prev?.load === true, files };

            if (prev?.version === version) report.push(`${tag}: ${version} (unchanged)`);
            else {
                report.push(`${tag}: ${prev ? `${prev.version} is now ${version}` : `${version} (new)`}`);
                report.push(...propChanges(before, manifestProps(join(src, 'manifest.json'))));
            }
        }
        cpSync(join(tmp, 'LICENSE'), join(stage, 'LICENSE'));
        writeLock(lock, join(stage, 'starbase.lock.json'));

        const staged = checkProblems(stage, { gitIgnore: false });
        const ignored = ignoredPaths(targets);
        if (staged.length > 0 || ignored.length > 0) {
            for (const p of staged) console.error(`FAIL ${p}`);
            for (const p of ignored) console.error(`FAIL ${p}: ignored by git (see .gitignore)`);
            console.error(`pull aborted, ${relative(ROOT, DIR)} is unchanged`);
            return 1;
        }

        for (const e of readdirSync(DIR)) {
            const m = FOLDER_RE.exec(e);
            if (m && slugs.includes(m[1])) rmSync(join(DIR, e), { recursive: true, force: true });
        }
        for (const slug of slugs) renameSync(join(stage, `${slug}@${versions[slug]}`), join(DIR, `${slug}@${versions[slug]}`));
        renameSync(join(stage, 'LICENSE'), join(DIR, 'LICENSE'));
        renameSync(join(stage, 'starbase.lock.json'), LOCK);
        cleanup();

        const status = check();
        console.log(`\nStarbase ${describe} (${commit}), catalog ${lock.catalog}, ${datastar}`);
        for (const line of report) console.log(line);
        return status;
    } finally {
        cleanup();
    }
}

function load([slug, state]) {
    if (!slug || !['on', 'off'].includes(state)) throw new UsageError('load needs <slug> on|off');
    const lock = readLock();
    if (!lock?.components?.[slug]) throw new UsageError(`'${slug}' is not vendored`);
    lock.components[slug].load = state === 'on';
    writeLock(lock);
    return check();
}

function remove([slug]) {
    if (!slug || !SLUG_RE.test(slug)) throw new UsageError('remove needs <slug>');
    const lock = readLock();
    if (!lock?.components?.[slug]) throw new UsageError(`'${slug}' is not vendored`);
    for (const e of readdirSync(DIR)) if (e.startsWith(`${slug}@`)) rmSync(join(DIR, e), { recursive: true, force: true });
    delete lock.components[slug];
    writeLock(lock);
    return check();
}

async function verifyRemote(args) {
    const i = args.indexOf('--base');
    const base = (i >= 0 ? args[i + 1] : '')?.replace(/\/+$/, '');
    if (!base) throw new UsageError('verify-remote needs --base <starbase url>');
    const lock = readLock();
    if (!lock) throw new UsageError('no lock file');

    const health = (await fetch(`${base}/healthz`).then((r) => r.text(), (e) => `unreachable (${e.message})`)).trim();
    console.log(`instance: ${health}; pinned: ${lock.describe}`);
    if (!health.split(/\s+/).includes(lock.describe)) {
        console.log(`note: the instance does not report ${lock.describe}; comparing against catalog ${lock.catalog}`);
    }
    const mapUrl = `${base}/c/@${lock.catalog}/importmap.json`;
    let integrity;
    try {
        const res = await fetch(mapUrl);
        if (!res.ok) {
            console.error(`FAIL ${mapUrl} answered ${res.status}`);
            return 1;
        }
        integrity = (await res.json()).integrity ?? {};
    } catch (e) {
        console.error(`FAIL ${mapUrl}: ${e.cause?.message ?? e.message}`);
        return 1;
    }
    const bySuffix = new Map(Object.entries(integrity).map(([url, hash]) => [new URL(url).pathname.replace(/^.*\/c\//, ''), hash]));

    let failed = 0;
    let compared = 0;
    for (const [slug, c] of Object.entries(lock.components)) {
        for (const [rel, hash] of Object.entries(c.files)) {
            const key = `${slug}@${c.version}/${rel}`;
            const file = join(DIR, `${slug}@${c.version}`, rel);
            const onDisk = existsSync(file) ? sri(readFileSync(file)) : 'missing';
            if (onDisk !== hash) {
                console.error(`FAIL ${key}: on disk ${onDisk}, lock ${hash}`);
                failed++;
                continue;
            }
            const remote = bySuffix.get(key);
            if (remote === undefined) {
                if (/\.m?js$/.test(rel)) {
                    console.error(`FAIL ${key}: not in the instance's integrity map`);
                    failed++;
                } else console.log(`skip ${key}: the integrity map covers modules only`);
                continue;
            }
            compared++;
            if (remote === hash) console.log(`ok   ${key}`);
            else {
                console.error(`FAIL ${key}: instance ${remote}, lock ${hash}`);
                failed++;
            }
        }
    }
    console.log(`${compared} file(s) compared with ${mapUrl}, ${failed} mismatch(es)`);
    return failed === 0 && compared > 0 ? 0 : 1;
}

async function main(argv) {
    const [cmd, ...args] = argv;
    switch (cmd) {
        case 'check':
            return check();
        case 'pull':
            return pull(args);
        case 'load':
            return load(args);
        case 'remove':
            return remove(args);
        case 'verify-remote':
            return verifyRemote(args);
        default:
            throw new UsageError('commands: check | pull --from <dir> --ref <ref> <slug>... | load <slug> on|off | remove <slug> | verify-remote --base <url>');
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        process.exitCode = await main(process.argv.slice(2));
    } catch (e) {
        console.error(e instanceof UsageError ? e.message : e);
        process.exitCode = e instanceof UsageError ? 2 : 1;
    }
}
