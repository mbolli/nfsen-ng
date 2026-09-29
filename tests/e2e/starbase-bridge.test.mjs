// The Starbase theming bridge (ROCKET-SPEC 5.2, 8.3). STARBASE_DIR adds the catalog audit; screenshots go to OUT.
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, withPage } from './lib/cdp.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const VENDOR = join(ROOT, 'frontend/js/starbase');
const OUT = process.env.OUT || '/tmp/starbase-bridge';
const STARBASE_DIR = process.env.STARBASE_DIR || '';
const THEMES = ['light', 'dark'];
const NOT_COLOUR = [
    '--sb-notch',
    '--sb-frame-step',
    '--sb-control-radius',
    '--sb-radius',
    '--sb-radius-sm',
    '--sb-radius-lg',
    '--sb-focus-ring',
    '--sb-z-toast',
    '--sb-z-tooltip',
];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const CATALOG = {
    input: `<sb-input label="Host" value="10.0.0.1" hint="IPv4 or IPv6"></sb-input><sb-input label="Port" value="99999" pattern="[0-9]{1,4}" error="Out of range"></sb-input>`,
    select: `<sb-select label="Source" value="gw2" options='["gw1","gw2","gw3"]'></sb-select>`,
    tabs: `<sb-tabs labels='["Flows","Talkers","Alerts"]' selected="1"></sb-tabs>`,
    toggle: `<sb-toggle checked label="Live"></sb-toggle><sb-toggle label="Paused"></sb-toggle>`,
    'radio-group': `<sb-radio-group label="Unit" value="bits" orientation="horizontal"><sb-radio value="bytes">Bytes</sb-radio><sb-radio value="bits">Bits</sb-radio></sb-radio-group>`,
    slider: `<sb-slider label="Top N" value="10" min="5" max="50"></sb-slider>`,
    range: `<sb-range label="Ports" value='{"start":80,"end":443}' min="1" max="1024"></sb-range>`,
    modal: `<sb-modal inline heading="Delete rule"><span>Delete this alert rule?</span><sb-button slot="footer" size="sm" variant="outline">Cancel</sb-button><sb-button slot="footer" size="sm" variant="danger">Delete</sb-button></sb-modal>`,
    card: `<sb-card heading="Traffic">Flows per second<span slot="footer">Last hour</span></sb-card>`,
    alert: ['info', 'success', 'warning', 'danger']
        .map((v) => `<sb-alert variant="${v}" heading="Import ${v}">Last run 5 minutes ago.</sb-alert>`)
        .join(''),
    button: ['primary', 'outline', 'ghost', 'danger'].map((v) => `<sb-button variant="${v}">${v}</sb-button>`).join(''),
    'copy-button': `<sb-copy-button value="proto tcp"></sb-copy-button>`,
    details: `<sb-details open summary="Filter">proto tcp and port 443</sb-details>`,
    tooltip: `<sb-tooltip content="Bytes per second" open><span tabindex="0">Rate</span></sb-tooltip>`,
    tree: `<sb-tree value="flows" expanded="pages" items='[{"id":"pages","label":"Pages","children":[{"id":"overview","label":"Overview"},{"id":"flows","label":"Flows"},{"id":"alerts","label":"Alerts"}]}]'></sb-tree>`,
    dropdown: `<sb-dropdown type="radio" value="1h" label="Range" items='[{"value":"1h","label":"Last hour"},{"value":"24h","label":"Last day"},{"value":"7d","label":"Last week"}]'></sb-dropdown>`,
};

// Contrast and state findings of the catalog, each with the ROCKET-SPEC 5.3 rule its adopter applies first. A chroma
// leak is never allowed. Keys read "<kind> <tag> <part>"; state-forced is the state check under forced colours.
const SELECT_OPTION =
    '5.3.6: the selected option differs only in weight (select.js:140), --sb-brand-light being --focus-color like the option text; options have no part, so it needs a part or a selected background upstream first';
const ALLOWED = {
    'state-forced sb-input input':
        "5.3.6: under forced colours the invalid input's danger border is forced like the valid one's, as on nfsen-ng's own inputs (ui.css:455), and only the error text remains; an adopter adds a forced-colours outline on sb-input::part(input):invalid",
    'state sb-select option': SELECT_OPTION,
    'state-forced sb-select option': SELECT_OPTION,
    'state-forced sb-tree item.selected':
        '5.3.6: under forced colours the selected item paints Canvas like the others (U5); an adopter adds the 5.4 rule sb-tree::part(item selected) { outline: 2px solid Highlight }',
};

// One of each fault, sb-alert's border tint on another tag, and a top-level slot with text, so an audit that finds too
// little or too much, or trips over a slot without a parent element, fails.
const CONTROL = 'nfsen-bridge-control';
const CONTROL_FINDINGS = [
    'chroma nfsen-bridge-control leak',
    'chroma nfsen-bridge-control navy',
    'chroma nfsen-bridge-control navy-border',
    'chroma nfsen-bridge-control tinted',
    'chroma nfsen-bridge-control turned',
    'contrast nfsen-bridge-control faint',
    'state nfsen-bridge-control tab.selected',
];
function defineControl(tag) {
    customElements.define(
        tag,
        class extends HTMLElement {
            connectedCallback() {
                if (this.shadowRoot) return;
                this.attachShadow({ mode: 'open' }).innerHTML =
                    '<span part="leak" style="border: 2px solid var(--sb-unmapped, #b09aff)"></span> ' +
                    '<span part="navy" style="padding: 0.5rem; background: var(--sb-unmapped, #0b1224)"></span> ' +
                    '<span part="navy-border" style="border: 2px solid var(--sb-unmapped, #283552)"></span> ' +
                    '<span part="turned" style="border: 2px solid color-mix(in oklch, var(--info) 30%, oklch(89.5% 0 0))"></span> ' +
                    '<span part="tinted" style="border: 2px solid color-mix(in oklab, var(--info) 30%, var(--border))"></span> ' +
                    '<span part="faint" style="color: var(--surface-3)">faint</span> ' +
                    '<span part="tab selected" style="font-weight: 700">A</span><span part="tab">B</span><slot></slot>';
            }
        }
    );
    return true;
}

// A host rule: its colour token resolves on the host alone, the size is no colour, and the nested token is not read.
const HOST_RULE = `${CONTROL} { --sb-bridge-tone: var(--danger); --sb-bridge-gap: var(--size-3); @media all { --sb-bridge-nested: var(--info); } }`;

/** Style rules outside any at-rule as [selectors, declarations], without what nested blocks set (StarbaseBridgeTest.php). */
function topLevelRules(css) {
    const rules = [];
    let [depth, prelude, body, pending] = [0, '', '', ''];
    for (const ch of css.replace(/\/\*[\s\S]*?\*\//g, '')) {
        if (ch === '{') {
            if (++depth === 1) body = '';
            pending = '';
        } else if (ch === '}' && depth > 0) {
            if (--depth === 0) {
                if (!prelude.trim().startsWith('@')) rules.push([prelude.trim(), body + pending]);
                prelude = '';
            }
            pending = '';
        } else if (depth === 0) prelude = ch === ';' ? '' : prelude + ch;
        else if (depth === 1) {
            pending += ch;
            if (ch === ';') [body, pending] = [body + pending, ''];
        }
    }
    return rules;
}

/** The --sb-* tokens starbase.css declares, name => value: those of :root, and per tag those of its host rules. */
function bridgeTokens(css = readFileSync(join(ROOT, 'frontend/css/starbase.css'), 'utf8')) {
    const [root, hosts] = [{}, {}];
    for (const [selectors, body] of topLevelRules(css)) {
        const declared = Object.fromEntries([...body.matchAll(/(--sb-[a-z0-9-]+)\s*:\s*([^;]+)(?:;|$)/g)].map((m) => [m[1], m[2].trim()]));
        for (const selector of selectors.split(',').map((x) => x.trim())) {
            if (selector === ':root') Object.assign(root, declared);
            else if (/^[a-z][a-z0-9]*-[a-z0-9-]*$/.test(selector)) hosts[selector] = { ...hosts[selector], ...declared };
        }
    }
    return { root, hosts };
}

/** The markup of the `preview:` block in a Starbase README's front matter. */
function preview(readme) {
    if (!existsSync(readme)) return '';
    const lines = readFileSync(readme, 'utf8').split('\n');
    const start = lines.indexOf('preview: |');
    if (start < 0) return '';
    const out = [];
    for (const line of lines.slice(start + 1)) {
        if (!line.startsWith('  ')) break;
        out.push(line.slice(2));
    }
    return out.join('\n');
}

/** The lock's components; fails when it lists none or a folder is missing. */
function vendored() {
    const lock = JSON.parse(readFileSync(join(VENDOR, 'starbase.lock.json'), 'utf8'));
    const components = Object.entries(lock.components ?? {});
    assert.ok(components.length > 0, 'starbase.lock.json lists no component');
    return components.map(([slug, c]) => {
        for (const key of ['tag', 'version', 'entry']) assert.equal(typeof c?.[key], 'string', `lock entry ${slug} has no ${key}`);
        const folder = `${slug}@${c.version}`;
        assert.ok(existsSync(join(VENDOR, folder)), `frontend/js/starbase/${folder} is missing`);
        assert.ok(existsSync(join(VENDOR, folder, c.entry)), `frontend/js/starbase/${folder}/${c.entry} is missing`);
        return {
            tag: c.tag,
            path: `starbase/${folder}/${c.entry}`,
            markup: preview(join(VENDOR, folder, 'README.md')) || `<${c.tag}></${c.tag}>`,
        };
    });
}

/** The catalog components under STARBASE_DIR, read from disk. */
function catalog() {
    return Object.entries(CATALOG).map(([slug, markup]) => {
        const file = join(STARBASE_DIR, 'components', slug, `${slug}.js`);
        assert.ok(existsSync(file), `${file} is missing`);
        return { tag: `sb-${slug}`, source: readFileSync(file, 'utf8'), markup };
    });
}

async function setTheme(page, mode) {
    await page.evaluate(`window.__nfsenTheme.choose(${JSON.stringify(mode)})`);
    await page.waitFor(`document.documentElement.dataset.theme === ${JSON.stringify(mode)}`, { label: `theme ${mode}` });
    await sleep(150);
}

async function media(page, features) {
    await page.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }, ...features] });
    await sleep(300);
}

/** Page side: each token against its declared value, on a `tag` host if given, by a probe pair showing rgb(1, 2, 3) on failure. */
function probeTokens(tokens, tag) {
    const box = document.createElement('div');
    box.style.color = 'rgb(1, 2, 3)';
    document.getElementById('client-root').append(box);
    const out = [];
    for (const [name, value] of Object.entries(tokens)) {
        const [a, b] = [document.createElement(tag ?? 'span'), document.createElement('span')];
        a.style.color = `var(${name})`;
        b.style.color = value;
        box.append(a, b);
        out.push({ name, value, bridged: getComputedStyle(a).color, target: getComputedStyle(b).color });
    }
    box.remove();
    return out;
}

/** Page side: the chroma, contrast and state findings of ROCKET-SPEC 8.3 for every host of `tags` in #client-root. */
function auditHosts({ tags, checks }) {
    const lin = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    const gam = (c) => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055);
    const toLab = ([r, g, b]) => {
        const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
        const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
        const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
        return [
            0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
            1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
            0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
        ];
    };
    const fromLab = ([L, A, B]) => {
        const l = (L + 0.3963377774 * A + 0.2158037573 * B) ** 3;
        const m = (L - 0.1055613458 * A - 0.0638541728 * B) ** 3;
        const s = (L - 0.0894841775 * A - 1.291485548 * B) ** 3;
        return [
            4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
            -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
            -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
        ];
    };
    const ctx = Object.assign(document.createElement('canvas'), { width: 1, height: 1 }).getContext('2d', { willReadFrequently: true });
    const nums = (t) =>
        t
            .split(/[\s,/]+/)
            .filter(Boolean)
            .map((x) => (x === 'none' ? 0 : x.endsWith('%') ? Number.parseFloat(x) / 100 : Number.parseFloat(x)));
    /** A computed colour as OKLab plus alpha. */
    const parse = (s) => {
        const rgb = /^rgba?\((.*)\)$/.exec(s);
        if (rgb) {
            const [r, g, b, a = 1] = nums(rgb[1]);
            return { lab: toLab([r, g, b].map((c) => lin(c / 255))), alpha: a };
        }
        const oklab = /^oklab\((.*)\)$/.exec(s);
        if (oklab) {
            const [L, A, B, a = 1] = nums(oklab[1]);
            return { lab: [L, A, B], alpha: a };
        }
        const oklch = /^oklch\((.*)\)$/.exec(s);
        if (oklch) {
            const [L, C, H, a = 1] = nums(oklch[1]);
            return { lab: [L, C * Math.cos((H * Math.PI) / 180), C * Math.sin((H * Math.PI) / 180)], alpha: a };
        }
        const colorFn = /^color\(srgb(-linear)?\s+(.*)\)$/.exec(s);
        if (colorFn) {
            const [r, g, b, a = 1] = nums(colorFn[2]);
            return { lab: toLab(colorFn[1] ? [r, g, b] : [r, g, b].map(lin)), alpha: a };
        }
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = s;
        ctx.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
        return { lab: toLab([r, g, b].map((c) => lin(c / 255))), alpha: a / 255 };
    };
    const COLOUR = /(?:rgba?|oklch|oklab|lab|lch|color|hsla?|hwb)\([^()]*\)/g;
    const chroma = (c) => Math.hypot(c.lab[1], c.lab[2]);
    const distance = (a, b) => Math.hypot(a.lab[0] - b.lab[0], a.lab[1] - b.lab[1], a.lab[2] - b.lab[2]);
    const srgb = (c) => fromLab(c.lab).map((v) => gam(Math.min(1, Math.max(0, v))));
    const over = (top, bottom) => srgb(top).map((v, i) => v * top.alpha + bottom[i] * (1 - top.alpha));
    const luminance = (rgb) => {
        const [r, g, b] = rgb.map((v) => lin(v));
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };

    // nfsen-ng's status colours with their -subtle and -emphasis tokens and its series colours; on sb-alert only, also the
    // heading and border tints it mixes from its status tone (alert.js:28, 70% into --text-1, 30% into --border).
    const probe = document.createElement('span');
    document.getElementById('client-root').append(probe);
    const resolved = (value) => {
        probe.style.color = value;
        return parse(getComputedStyle(probe).color);
    };
    const STATUS = ['danger', 'warning', 'success', 'info'];
    const refs = [
        ...STATUS.flatMap((t) => [`var(--${t})`, `var(--${t}-subtle)`, `var(--${t}-emphasis)`]),
        ...[1, 2, 3, 4, 5, 6, 7, 8].map((i) => `var(--series-${i})`),
    ].map(resolved);
    const alertRefs = STATUS.flatMap((t) => [
        `color-mix(in oklab, var(--${t}) 70%, var(--text-1))`,
        `color-mix(in oklab, var(--${t}) 30%, var(--border))`,
    ]).map(resolved);
    probe.remove();
    // A reference colour passes at any alpha; any other colour with chroma is a leak.
    const leak = (tag, c) =>
        c.alpha > 0 &&
        chroma(c) > 0.02 &&
        ![...refs, ...(tag === 'sb-alert' ? alertRefs : [])].some((r) => distance(r, c) <= 0.01);
    const hosts = [...document.querySelectorAll('#client-root *')].filter((el) => tags.includes(el.localName));
    const trees = new Map(); // tag => elements rendered by its instances
    // Shadow trees, and the light DOM slotted into them; a nested host of `tags` is audited as itself.
    const collect = (tag, root) => {
        for (const el of root.querySelectorAll('*')) {
            trees.get(tag).push(el);
            if (el.shadowRoot && !tags.includes(el.localName)) collect(tag, el.shadowRoot);
            if (el.localName !== 'slot') continue;
            for (const slotted of el.assignedElements({ flatten: true })) {
                if (!tags.includes(slotted.localName)) trees.get(tag).push(slotted, ...slotted.querySelectorAll('*'));
            }
        }
    };
    for (const host of hosts) {
        if (!trees.has(host.localName)) trees.set(host.localName, []);
        trees.get(host.localName).push(host);
        collect(host.localName, host.shadowRoot ?? host);
    }
    const hostOf = (el) => (el.getRootNode() instanceof ShadowRoot ? el.getRootNode().host : el.closest(tags.join(',')));
    const label = (tag, el) => {
        const variant = hostOf(el)?.getAttribute('variant') ? `[variant=${hostOf(el).getAttribute('variant')}]` : '';
        const what = hosts.includes(el)
            ? ':host'
            : el.getAttribute('part') || el.getAttribute('role') || `${el.localName}${el.classList[0] ? `.${el.classList[0]}` : ''}`;
        return `${tag}${variant} ${what.replace(/\s+/g, '.')}`;
    };
    const findings = [];
    const add = (kind, tag, el, detail) => {
        const key = `${kind} ${label(tag, el)}`;
        if (!findings.some((f) => f.key === key)) findings.push({ key, detail });
    };

    const COLOUR_PROPS = [
        'color',
        'background-color',
        'background-image',
        'border-top-color',
        'border-right-color',
        'border-bottom-color',
        'border-left-color',
        'outline-color',
        'text-decoration-color',
        'box-shadow',
        'text-shadow',
        'fill',
        'stroke',
        'caret-color',
        'accent-color',
        '-webkit-text-fill-color',
    ];
    const styles = (el) => [
        getComputedStyle(el),
        ...['::before', '::after'].map((p) => getComputedStyle(el, p)).filter((cs) => cs.content !== 'none' && cs.content !== 'normal'),
    ];

    if (checks.includes('chroma')) {
        for (const [tag, els] of trees) {
            for (const el of els) {
                for (const cs of styles(el)) {
                    for (const prop of COLOUR_PROPS) {
                        for (const value of cs.getPropertyValue(prop).match(COLOUR) ?? []) {
                            if (leak(tag, parse(value))) add('chroma', tag, el, `${prop}: ${value}`);
                        }
                    }
                }
            }
        }
    }

    const flatParent = (n) => n.assignedSlot ?? n.parentElement ?? (n.parentNode instanceof ShadowRoot ? n.parentNode.host : null);
    const background = (el) => {
        const layers = [];
        for (let n = el; n; n = flatParent(n)) {
            const c = parse(getComputedStyle(n).backgroundColor);
            if (c.alpha > 0) layers.push(c);
            if (c.alpha >= 1) break;
        }
        return layers.reverse().reduce((bottom, top) => over(top, bottom), [1, 1, 1]);
    };
    const hasText = (el) => [...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '');
    const slottedText = (el) =>
        el.localName === 'slot' &&
        el.assignedNodes({ flatten: true }).some((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '');
    const shown = (el) => {
        const target =
            el.localName === 'slot'
                ? el.assignedNodes({ flatten: true }).find((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '')
                : el;
        const range = document.createRange();
        range.selectNodeContents(target);
        const box = el.localName === 'slot' ? (el.parentElement ?? el.getRootNode().host) : el;
        return range.getClientRects().length > 0 && box.checkVisibility({ opacityProperty: true, visibilityProperty: true });
    };
    let texts = 0;
    let states = 0;
    if (checks.includes('contrast')) {
        for (const [tag, els] of trees) {
            for (const el of els) {
                if (hosts.includes(el) || !(hasText(el) || slottedText(el)) || !shown(el)) continue;
                const bg = background(el);
                const fg = parse(getComputedStyle(el).color);
                const [l1, l2] = [luminance(over(fg, bg)), luminance(bg)].sort((a, b) => b - a);
                const ratio = (l1 + 0.05) / (l2 + 0.05);
                texts++;
                if (ratio < 4.5) add('contrast', tag, el, `${ratio.toFixed(2)}:1`);
            }
        }
    }

    const STATE_PROPS = [
        'color',
        'background-image',
        'border-top-color',
        'border-bottom-color',
        'border-left-color',
        'border-right-color',
        'border-top-width',
        'border-bottom-width',
        'border-left-width',
        'border-right-width',
        'outline-style',
        'outline-color',
        'outline-width',
        'box-shadow',
        'text-decoration-line',
        'text-decoration-color',
        'opacity',
        'visibility',
        'display',
        'transform',
        'scale',
        'fill',
        'stroke',
        'font-style',
        'clip-path',
    ];
    // The painted background, not the computed one: under forced colours a selected Canvas over Canvas looks the same.
    const signature = (el) =>
        JSON.stringify(
            [el, ...el.querySelectorAll('*')].flatMap((n) => {
                const [own, ...pseudos] = styles(n);
                return [
                    background(n).map((v) => Math.round(v * 255)),
                    STATE_PROPS.map((p) => own.getPropertyValue(p)),
                    ...pseudos.map((cs) => [cs.backgroundColor, ...STATE_PROPS.map((p) => cs.getPropertyValue(p))]),
                ];
            })
        );
    const STATES = ['selected', 'checked', 'current', 'invalid'];
    const ARIA = { 'aria-selected': 'true', 'aria-checked': 'true', 'aria-pressed': 'true', 'aria-invalid': 'true', 'aria-current': null };
    if (checks.some((c) => c.startsWith('state'))) {
        const kind = checks.find((c) => c.startsWith('state'));
        for (const [tag, els] of trees) {
            for (const el of els) {
                const parts = [...(el.part ?? [])];
                let defaults = [];
                if (parts.some((p) => STATES.includes(p))) {
                    const base = parts.filter((p) => !STATES.includes(p)).join(' ');
                    defaults = els.filter((d) => d !== el && [...(d.part ?? [])].join(' ') === base);
                } else {
                    const attr = Object.keys(ARIA).find(
                        (a) => el.hasAttribute(a) && (ARIA[a] === null ? el.getAttribute(a) !== 'false' : el.getAttribute(a) === ARIA[a])
                    );
                    if (!attr) continue;
                    defaults = els.filter(
                        (d) =>
                            d !== el &&
                            d.getAttribute('role') === el.getAttribute('role') &&
                            d.getAttribute('part') === el.getAttribute('part') &&
                            (d.getAttribute(attr) ?? 'false') === 'false'
                    );
                }
                if (defaults.length === 0) continue;
                states++;
                const own = signature(el);
                const twin = defaults.find((d) => signature(d) === own);
                if (twin) add(kind, tag, el, `looks like ${label(tag, twin)} apart from font-weight`);
            }
        }
    }
    return { hosts: hosts.length, elements: [...trees.values()].reduce((n, els) => n + els.length, 0), texts, states, findings };
}

/** Page side: every host of `tags` is defined and has rendered, into its shadow root or over its authored children. */
function rendered(tags) {
    const hosts = [...document.querySelectorAll('#client-root *')].filter((el) => tags.includes(el.localName));
    const drawn = (h) =>
        h.shadowRoot ? h.shadowRoot.childElementCount > 0 : h.innerHTML.trim() !== '' && h.innerHTML !== window.__sbAuthored.get(h);
    return tags.every((t) => hosts.some((h) => h.localName === t)) && hosts.every((h) => h.matches(':defined') && drawn(h));
}

async function mount(page, id, components) {
    const cells = components.map(
        (c) =>
            `<section style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-3); padding: var(--size-3); display: grid; gap: var(--size-2); align-content: start"><h3 style="margin: 0; font-size: var(--font-size-00); color: var(--text-2)">${c.tag}</h3>${c.markup}</section>`
    );
    await page.evaluate(`(() => {
        const box = document.createElement('div');
        box.id = ${JSON.stringify(id)};
        box.style.cssText = 'position: fixed; inset: 0; z-index: 1000; overflow: auto; padding: 1rem; background: var(--surface-1); color: var(--text-1); display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr)); align-content: start';
        box.innerHTML = ${JSON.stringify(cells.join(''))};
        window.__sbAuthored ??= new WeakMap();
        for (const el of box.querySelectorAll('*')) window.__sbAuthored.set(el, el.innerHTML);
        document.getElementById('client-root').append(box);
    })()`);
    const tags = components.map((c) => c.tag);
    await page.waitFor(`(${rendered.toString()})(${JSON.stringify(tags)})`, { timeout: 10000, label: `${tags.join(', ')} to render` });
    await sleep(400);
    return tags;
}

/** Audits in light, dark and forced colours with screenshots; prints the allowed findings, returns every finding. */
async function auditAll(page, tags, name, { catalog }) {
    const found = [];
    for (const mode of THEMES) {
        await media(page, [{ name: 'prefers-color-scheme', value: mode }]);
        await setTheme(page, mode);
        const r = await page.evaluate(`(${auditHosts.toString()})(${JSON.stringify({ tags, checks: ['chroma', 'contrast', 'state'] })})`);
        assert.ok(r.hosts >= tags.length && r.elements > r.hosts, `${name}: nothing rendered to audit in ${mode}`);
        if (catalog) assert.ok(r.texts > 0 && r.states > 0, `${name}: no text or no selected, checked or current state in ${mode}`);
        console.log(`  ${name} ${mode}: ${r.hosts} hosts, ${r.elements} elements, ${r.texts} texts, ${r.states} states`);
        found.push(...r.findings.map((f) => ({ ...f, mode })));
        await page.screenshot(join(OUT, `${name}-${mode}.png`));
    }
    await media(page, [{ name: 'forced-colors', value: 'active' }]);
    const r = await page.evaluate(`(${auditHosts.toString()})(${JSON.stringify({ tags, checks: ['state-forced'] })})`);
    found.push(...r.findings.map((f) => ({ ...f, mode: 'forced' })));
    await page.screenshot(join(OUT, `${name}-forced.png`));
    await media(page, []);

    for (const f of found) {
        if (ALLOWED[f.key]) console.log(`  allowed, ${f.mode}: ${f.key} (${f.detail}): ${ALLOWED[f.key]}`);
        else console.error(`  FAIL, ${f.mode}: ${f.key} (${f.detail})`);
    }
    return found;
}

async function boot(page) {
    await media(page, []);
    await page.navigate(`${BASE}/`);
    await page.waitForBoot();
    await sleep(800);
}

async function tokens(page) {
    const { root: declared, hosts } = bridgeTokens();
    assert.deepEqual(
        NOT_COLOUR.filter((name) => !(name in declared)),
        [],
        'NOT_COLOUR lists tokens starbase.css does not declare'
    );
    const colours = Object.fromEntries(Object.entries(declared).filter(([name]) => !NOT_COLOUR.includes(name)));
    assert.ok(Object.keys(colours).length > 0, 'starbase.css declares no colour token');
    const control = bridgeTokens(HOST_RULE).hosts;
    assert.deepEqual(control, { [CONTROL]: { '--sb-bridge-tone': 'var(--danger)', '--sb-bridge-gap': 'var(--size-3)' } }, 'host rule read');
    await page.evaluate(
        `document.head.append(Object.assign(document.createElement('style'), { id: 'sb-host-rule', textContent: ${JSON.stringify(HOST_RULE)} }))`
    );
    const seen = {};
    for (const mode of THEMES) {
        await media(page, [{ name: 'prefers-color-scheme', value: mode }]);
        await setTheme(page, mode);
        const probes = await page.evaluate(`(${probeTokens.toString()})(${JSON.stringify(colours)})`);
        assert.equal(probes.length, Object.keys(colours).length, `${mode}: not every colour token was probed`);
        for (const p of probes) {
            assert.notEqual(p.target, 'rgb(1, 2, 3)', `${mode}: ${p.value} (for ${p.name}) does not resolve to a colour`);
            assert.equal(p.bridged, p.target, `${mode}: ${p.name} is ${p.bridged}, ${p.value} is ${p.target}`);
        }
        seen[mode] = Object.fromEntries(probes.map((p) => [p.name, p.bridged]));
        // A host token whose value is no colour is a size or other knob.
        const checked = [];
        for (const [tag, set] of Object.entries({ ...hosts, ...control })) {
            for (const p of await page.evaluate(`(${probeTokens.toString()})(${JSON.stringify(set)}, ${JSON.stringify(tag)})`)) {
                if (p.target === 'rgb(1, 2, 3)') continue;
                assert.equal(p.bridged, p.target, `${mode}: ${p.name} on ${tag} is ${p.bridged}, ${p.value} is ${p.target}`);
                checked.push(`${tag} ${p.name}`);
            }
        }
        assert.ok(
            checked.includes(`${CONTROL} --sb-bridge-tone`) && !checked.includes(`${CONTROL} --sb-bridge-gap`),
            `${mode}: host probe`
        );
        console.log(`  tokens ${mode}: ${probes.length} in :root, ${checked.length - 1} in host rules`);
    }
    await page.evaluate(`document.getElementById('sb-host-rule').remove()`);
    const flipped = Object.keys(seen.light).filter((name) => seen.light[name] !== seen.dark[name]);
    assert.ok(flipped.includes('--sb-bg') && flipped.includes('--sb-text-1'), 'the bridge resolves per theme');
}

export default async function starbaseBridgeTest() {
    assert.deepEqual(
        Object.keys(ALLOWED).filter((key) => key.startsWith('chroma ')),
        [],
        'a chroma leak is never allowed'
    );
    mkdirSync(OUT, { recursive: true });
    const components = vendored();
    const found = [];

    await withPage(async (page) => {
        await boot(page);
        await tokens(page);

        const bundle = await page.evaluate(`JSON.parse(document.querySelector('script[type=importmap]').textContent).imports.datastar`);
        for (const c of components) {
            // Resolved like the layout's script tags: next to the bundle, whatever the base path.
            await page.evaluate(
                `import(new URL(${JSON.stringify(c.path)}, new URL(${JSON.stringify(bundle)}, location.href)).href).then(() => true)`
            );
        }
        const tags = await mount(page, 'sb-vendored', components);
        found.push(...(await auditAll(page, tags, 'vendored', { catalog: false })));
        assert.deepEqual(page.realErrors(), [], 'console errors while mounting the vendored components');

        await page.evaluate(`(${defineControl.toString()})(${JSON.stringify(CONTROL)})`);
        await mount(page, 'sb-control', [{ tag: CONTROL, markup: `<${CONTROL}>slotted</${CONTROL}>` }]);
        for (const mode of THEMES) {
            await setTheme(page, mode);
            const r = await page.evaluate(
                `(${auditHosts.toString()})(${JSON.stringify({ tags: [CONTROL], checks: ['chroma', 'contrast', 'state'] })})`
            );
            assert.deepEqual(r.findings.map((f) => f.key).sort(), CONTROL_FINDINGS, `${mode}: the audit finds each fault of the control`);
        }
    });

    if (STARBASE_DIR) {
        const listed = catalog();
        await withPage(
            async (page) => {
                await boot(page);
                await page.evaluate(`(() => {
                for (const [tag, source] of ${JSON.stringify(listed.map((c) => [c.tag, c.source]))}) {
                    const script = document.createElement('script');
                    script.type = 'module';
                    script.textContent = source;
                    document.head.append(script);
                }
                return true;
            })()`);
                const tags = listed.map((c) => c.tag);
                await page.waitFor(`${JSON.stringify(tags)}.every((t) => customElements.get(t))`, {
                    timeout: 10000,
                    label: 'catalog components to define',
                });
                await mount(page, 'sb-catalog', listed);
                // sb-input validates from its first commit on.
                await page.evaluate(
                    `document.querySelectorAll('#sb-catalog sb-input[pattern]').forEach((h) => h.shadowRoot.querySelector('input').dispatchEvent(new Event('change')))`
                );
                await page.waitFor(
                    `document.querySelector('#sb-catalog sb-input[pattern]').shadowRoot.querySelector('.error').textContent !== ''`,
                    {
                        label: 'sb-input to show its error',
                    }
                );
                found.push(...(await auditAll(page, tags, 'catalog', { catalog: true })));
                assert.deepEqual(page.realErrors(), [], 'console errors while mounting the catalog components');
            },
            { height: 1400 }
        );
        const stale = Object.keys(ALLOWED).filter((key) => !found.some((f) => f.key === key));
        assert.deepEqual(stale, [], 'ALLOWED lists findings that no longer occur');
    }
    assert.deepEqual(
        found.filter((f) => !ALLOWED[f.key]).map((f) => `${f.mode}: ${f.key}`),
        [],
        'findings not on ALLOWED'
    );
}

if (import.meta.url === `file://${process.argv[1]}`) {
    starbaseBridgeTest()
        .then(() => console.log('starbase-bridge: PASS'))
        .catch((e) => {
            console.error('starbase-bridge: FAIL\n', e);
            process.exit(1);
        });
}
