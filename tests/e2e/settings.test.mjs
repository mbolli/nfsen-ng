// Settings: the tabs by keyboard, the saved defaults in a fresh tab, the read-only tabs. Saves
// through the real save-settings action and restores every preference it changed.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';
import { CLEAR_TOASTS, toasts } from './lib/toasts.mjs';

export const MUTATING = true;

const TABS = ['general', 'sources', 'storage', 'integrations', 'system'];
const KEY_CODES = { ArrowRight: 39, ArrowLeft: 37, Home: 36, End: 35, Tab: 9 };
// The preferences this test changes, by signal.
const FIELDS = [
    'settings_defaultView',
    'settings_defaultRange',
    'settings_defaultUnit',
    'settings_theme',
    'settings_compactTables',
    'settings_rdnsEnabled',
];
const SWITCHES = { settings_compactTables: 'settingsCompactTables', settings_rdnsEnabled: 'settingsRdnsEnabled' };

async function press(page, key) {
    const params = { key, code: key, windowsVirtualKeyCode: KEY_CODES[key], nativeVirtualKeyCode: KEY_CODES[key] };
    await page.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...params });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...params });
}

const activeId = (page) => page.evaluate('document.activeElement?.id ?? ""');

async function selectedTab(page) {
    return page.evaluate(`(function(){
        var tab = document.querySelector('#page-settings [role=tab][aria-selected=true]');
        var panels = [...document.querySelectorAll('#page-settings [role=tabpanel]')].filter(function(p){ return !p.hidden; });
        return { tab: tab ? tab.id : null, panels: panels.map(function(p){ return p.id; }) };
    })()`);
}

async function assertTab(page, id, how) {
    await page.waitFor(`document.querySelector('#settingsTab-${id}')?.getAttribute('aria-selected') === 'true'`, {
        label: `tab ${id} selected (${how})`,
    });
    assert.deepEqual(
        await selectedTab(page),
        { tab: `settingsTab-${id}`, panels: [`settingsPanel-${id}`] },
        `${how}: only the ${id} panel shows`
    );
}

async function openTab(page, id) {
    await page.evaluate(`document.getElementById('settingsTab-${id}').click()`);
    await assertTab(page, id, `click on ${id}`);
}

/** Put the General form into `values` (signal name => value) through its controls. */
async function fillForm(page, values) {
    for (const [signal, value] of Object.entries(values)) {
        if (signal === 'settings_defaultUnit') {
            await page.evaluate(`document.getElementById('settingsDefaultUnit-${value}').click()`);
        } else if (SWITCHES[signal]) {
            await page.evaluate(
                `(function(){ var box = document.getElementById('${SWITCHES[signal]}'); if (box.checked !== ${value}) box.click(); })()`
            );
        } else {
            const id = {
                settings_defaultView: 'settingsDefaultView',
                settings_defaultRange: 'settingsDefaultRange',
                settings_theme: 'settingsTheme',
            }[signal];
            await page.setSelectValue(`#${id}`, value);
        }
        assert.deepEqual(await page.signalValue(signal), value, `the form sets ${signal}`);
    }
}

/** Submit a Settings form with its button and wait for the server's toast. */
async function save(page, button = 'settingsSave') {
    await page.evaluate(CLEAR_TOASTS);
    await page.evaluate(`document.getElementById('${button}').click()`);
    await page.waitFor(
        `${toasts('#alerts-toast-container')}.some(function(t){ return t.variant === 'danger' || t.text === 'Settings saved.'; })`,
        {
            timeout: 10000,
            label: 'the save to answer',
        }
    );
    const error = await page.evaluate(
        `${toasts('#alerts-toast-container')}.filter(function(t){ return t.variant === 'danger'; }).map(function(t){ return t.text; }).join(' ')`
    );
    assert.equal(error, '', 'the save succeeds');
}

/** Navigate (or reload without a URL), giving up after 30 s: a restarting dev server can drop the request. */
async function load(page, url) {
    let timer;
    const late = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(`no load event for ${url ?? 'the reload'} within 30 s`)), 30000);
    });
    try {
        await Promise.race([url ? page.navigate(url) : page.reload(), late]);
    } finally {
        clearTimeout(timer);
    }
}

/** A new browser, with no theme of its own and a light OS, opened on the bare address. */
async function freshTab(fn) {
    return withPage(async (fresh) => {
        await fresh.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });
        await load(fresh, BASE + '/');
        await fresh.waitForBoot();
        return fn(fresh);
    });
}

async function openSettings(page) {
    await page.gotoPage('settings');
    await page.waitFor(`!!document.getElementById('settingsForm')`, { label: 'the Settings form' });
}

/** Put back the preferences in `values`: the General form, then the reverse DNS switch with its own Save. */
async function restore(page, values) {
    const { settings_rdnsEnabled: rdns, ...general } = values;
    await openTab(page, 'general');
    await fillForm(page, general);
    await save(page);
    await openTab(page, 'integrations');
    await fillForm(page, { settings_rdnsEnabled: rdns });
    await save(page, 'settingsRdnsSave');
}

export default async function settingsTest() {
    await withPage(async (page) => {
        await load(page, BASE + '/');
        await page.waitForBoot();
        await openSettings(page);

        const original = {};
        for (const signal of FIELDS) original[signal] = await page.signalValue(signal);
        const profileBefore = await freshTab((fresh) => fresh.signalValue('selected_profile'));

        let failure = null;
        try {
            assert.deepEqual(
                await page.evaluate(`[...document.querySelectorAll('#page-settings [role=tab]')].map(function(t){ return t.id; })`),
                TABS.map((id) => `settingsTab-${id}`),
                'Settings has its five tabs in order'
            );

            // Keyboard: Tab from the page title reaches the selected tab, arrows move and select.
            await page.evaluate(`document.getElementById('settingsTab-general').click(); document.getElementById('pageTitle').focus()`);
            await assertTab(page, 'general', 'reset');
            for (let i = 0; i < 5 && !(await activeId(page)).startsWith('settingsTab-'); i++) await press(page, 'Tab');
            assert.equal(await activeId(page), 'settingsTab-general', 'Tab from the page title lands on the selected tab');
            await press(page, 'ArrowRight');
            await assertTab(page, 'sources', 'ArrowRight');
            assert.equal(await activeId(page), 'settingsTab-sources', 'ArrowRight moves focus with the selection');
            await press(page, 'End');
            await assertTab(page, 'system', 'End');
            await press(page, 'ArrowRight');
            await assertTab(page, 'general', 'ArrowRight wraps from the last tab');
            await press(page, 'ArrowLeft');
            await assertTab(page, 'system', 'ArrowLeft wraps from the first tab');
            await press(page, 'Home');
            await assertTab(page, 'general', 'Home');
            assert.equal(
                await page.evaluate(
                    `[...document.querySelectorAll('#page-settings [role=tab]')].map(function(t){ return t.tabIndex; }).join(',')`
                ),
                '0,-1,-1,-1,-1',
                'only the selected tab is a Tab stop'
            );
            await press(page, 'Tab');
            assert.equal(await activeId(page), 'settingsPanel-general', 'Tab leaves the tab list for the panel');
            await press(page, 'Tab');
            assert.equal(await activeId(page), 'settingsDefaultView', 'the next Tab reaches the first field');

            // The read-only tabs.
            await openTab(page, 'sources');
            assert.ok(
                await page.evaluate(`document.querySelectorAll('#settingsCaptureDirs tbody tr').length > 0`),
                'Sources lists capture directories'
            );
            await openTab(page, 'storage');
            assert.match(
                await page.evaluate(`document.getElementById('settingsPanel-storage').textContent`),
                /nfsen-ng\.sqlite/,
                'Storage names the SQLite file'
            );
            await openTab(page, 'integrations');
            assert.match(
                await page.evaluate(`document.getElementById('integrationNetbox')?.textContent ?? ''`),
                /Netbox/,
                'Integrations shows the Netbox row'
            );
            await fillForm(page, { settings_rdnsEnabled: !original.settings_rdnsEnabled });
            await save(page, 'settingsRdnsSave');
            await page.withForcedColors(() => page.screenshot('/tmp/settings-integrations-forced-colors.png'));
            await openTab(page, 'system');
            const env = await page.evaluate(
                `[...document.querySelectorAll('#settingsEnv tbody th[scope=row]')].map(function(th){ return th.textContent.trim(); })`
            );
            assert.ok(
                env.includes('NFSEN_SOURCES') && env.includes('NFSEN_GEOIP_DB') && env.includes('NFSEN_TOPN_RETENTION_DAYS'),
                'System lists the variables'
            );
            const token = await page.evaluate(
                `[...document.querySelectorAll('#settingsEnv tbody tr')].find(function(r){ return r.querySelector('th')?.textContent.trim() === 'NFSEN_NETBOX_TOKEN'; })?.querySelector('td')?.textContent.trim()`
            );
            assert.ok(token === "''" || token === '***', `a secret is masked, got ${token}`);
            assert.ok(
                await page.evaluate(`document.querySelectorAll('#settingsEnv tbody th[scope=rowgroup]').length > 0`),
                'each variable group heads its rows'
            );
            assert.match(
                await page.evaluate(
                    `[...document.querySelectorAll('#settingsInEffect dt')].find(function(dt){ return dt.textContent.trim() === 'nfdump binary'; })?.nextElementSibling?.textContent ?? ''`
                ),
                /\/\S*nfdump/,
                'In effect shows the nfdump binary in use'
            );
            await openTab(page, 'general');
            await page.withForcedColors(() => page.screenshot('/tmp/settings-general-forced-colors.png'));

            const deployment = await page.evaluate(
                `[...document.querySelectorAll('#settingsTheme option')].find(function(o){ return o.value === ''; })?.textContent.trim() ?? ''`
            );
            assert.match(deployment, /^Deployment default \((System|Light|Dark)\)$/, 'the Theme select offers the deployment default');

            // Save a new set of defaults with the switch edited but not saved. A second open tab
            // takes the saved fields and keeps its own unsaved edit; a fresh tab opens with them.
            await fillForm(page, { settings_rdnsEnabled: original.settings_rdnsEnabled });
            await withPage(async (other) => {
                await load(other, BASE + '/#/settings');
                await other.waitForBoot();
                await other.waitForPage('settings');
                await openTab(other, 'general');
                const limit = (await other.signalValue('settings_flowLimit')) === 20 ? '50' : '20';
                await other.setSelectValue('#settingsFlowLimit', limit);
                await other.evaluate('window.__settingsTestOpen = true');

                await fillForm(page, {
                    settings_defaultView: 'flows',
                    settings_defaultRange: '7d',
                    settings_defaultUnit: 'bytes',
                    settings_theme: 'dark',
                    settings_compactTables: true,
                });
                await save(page);

                const reloaded = 'the second tab reloaded, so the dev server restarted under it: rerun the test';
                const stillOpen = () => other.evaluate('window.__settingsTestOpen === true');
                await other
                    .waitFor(
                        `document.getElementById('settingsDefaultRange').value === '7d' && document.getElementById('settingsCompactTables').checked`,
                        { label: 'the other tab to show the saved defaults' }
                    )
                    .catch(async (e) => {
                        throw (await stillOpen()) ? e : new Error(reloaded);
                    });
                assert.ok(await stillOpen(), reloaded);
                assert.equal(await other.signalValue('settings_defaultView'), 'flows', 'the other tab takes the saved default view');
                assert.equal(
                    await other.evaluate(`document.getElementById('settingsFlowLimit').value`),
                    limit,
                    'the other tab keeps its unsaved edit of a field the save did not change'
                );
            });
            await load(page);
            await page.waitForBoot();
            await page.waitForPage('settings');
            assert.equal(
                await page.evaluate('document.documentElement.dataset.density'),
                'compact',
                'compact tables set data-density after a reload'
            );
            assert.equal(
                await page.signalValue('settings_rdnsEnabled'),
                !original.settings_rdnsEnabled,
                'the reverse DNS Save persisted, and Save settings left the unsaved switch alone'
            );

            await freshTab(async (fresh) => {
                await fresh.waitForPage('flows');
                assert.equal(await fresh.evaluate('location.hash'), '#/flows', 'the bare address opens the default view');
                assert.equal(await fresh.signalValue('range_preset'), '7d', 'a new tab opens with the default range');
                assert.equal(await fresh.signalValue('graph_trafficUnit'), 'bytes', 'a new tab opens with the default unit');
                assert.equal(
                    await fresh.evaluate('document.documentElement.dataset.theme'),
                    'dark',
                    'the instance theme applies to a browser without its own'
                );
                assert.equal(await fresh.evaluate('document.documentElement.dataset.density'), 'compact', 'a new tab has compact tables');
                assert.equal(await fresh.signalValue('selected_profile'), profileBefore, 'saving keeps the selected profile');
            });

            // "Deployment default" gives the theme back to NFSEN_DEFAULT_THEME.
            await openSettings(page);
            await fillForm(page, { settings_theme: '' });
            await save(page);
            const expected = { System: 'light', Light: 'light', Dark: 'dark' }[deployment.match(/\((\w+)\)/)[1]];
            await freshTab(async (fresh) => {
                assert.equal(
                    await fresh.evaluate('document.documentElement.dataset.theme'),
                    expected,
                    'the deployment default theme applies again'
                );
            });
        } catch (e) {
            failure = e;
        }

        // A fresh context each attempt: a dev server restart drops the one in use. The detour
        // makes it a real load: from #/settings, the same URL is only a fragment navigation.
        for (let attempt = 1; ; attempt++) {
            try {
                await load(page, 'about:blank');
                await load(page, BASE + '/#/settings');
                await page.waitForBoot();
                await page.waitForPage('settings');
                await restore(page, original);
                break;
            } catch (e) {
                if (attempt < 3) continue;
                console.error('settings: could not restore the preferences', original, e);
                failure ??= e;
                break;
            }
        }
        if (failure) throw failure;

        assert.deepEqual(
            Object.fromEntries(await Promise.all(FIELDS.map(async (s) => [s, await page.signalValue(s)]))),
            original,
            'the preferences are restored'
        );

        // The pages that used to be Settings sections.
        await page.gotoPage('health');
        await page.gotoPage('alerts');

        // An old view id in the hash lands on the page it became.
        await page.evaluate(`location.hash = '#/statistics'`);
        await page.waitForPage('talkers');
        assert.equal(await page.evaluate('location.hash'), '#/talkers', 'a legacy hash is rewritten to the page id');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors on Settings, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    settingsTest()
        .then(() => console.log('settings: PASS'))
        .catch((e) => {
            console.error('settings: FAIL\n', e);
            process.exit(1);
        });
}
