/**
 * Live preview for alert notification templates (Settings → Alerts).
 * Mirrors AlertManager::buildTemplateVars() / resolveTemplate() in PHP, but uses
 * fake/example flow numbers (not real fired-alert data) so it works for brand-new,
 * unsaved rules with no server round-trip.
 */

const FAKE_METRICS = { flows: 42, packets: 3141, bytes: 123456.78 };

function fmt(n) {
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Build the {token: value} map for the preview.
 * @param {object} form - { rule, metric, operator, profile, sources, thresholdType, thresholdValue }
 * @returns {Record<string, string>}
 */
window.buildAlertPreviewVars = (form) => {
    const metric = form.metric || 'bytes';
    const operator = form.operator || '>';
    const thresholdValue = Number(form.thresholdValue) || 0;
    const thresholdDisplay =
        form.thresholdType === 'percent_of_avg' ? `${fmt(thresholdValue)}% of avg` : fmt(thresholdValue);
    const sourcesArr = Array.isArray(form.sources) ? form.sources : [];
    const sources = sourcesArr.length ? sourcesArr.join(', ') : 'gw1, gw2';

    return {
        '{rule}': form.rule || 'Example Rule',
        '{metric}': metric,
        '{value}': fmt(FAKE_METRICS[metric] ?? FAKE_METRICS.bytes),
        '{threshold}': thresholdDisplay,
        '{operator}': operator,
        '{condition}': `${metric} ${operator} ${thresholdDisplay}`,
        '{flows}': fmt(FAKE_METRICS.flows),
        '{packets}': fmt(FAKE_METRICS.packets),
        '{bytes}': fmt(FAKE_METRICS.bytes),
        '{profile}': form.profile || 'live',
        '{sources}': sources,
        '{time}': new Date().toISOString().slice(0, 19).replace('T', ' '),
    };
};

/**
 * Substitute {token} placeholders, mirroring AlertManager::resolveTemplate() + strtr().
 * Unknown tokens (absent from vars) are left untouched.
 * @param {string} template
 * @param {Record<string, string>} vars
 * @returns {string}
 */
window.renderAlertTemplatePreview = (template, vars) =>
    (template || '').replace(/\{[a-zA-Z_]+\}/g, (token) => (token in vars ? vars[token] : token));

/**
 * Insert a {token} into whichever text input/textarea currently has focus, at
 * the cursor position, replacing any selection -- lets one shared row of chip
 * buttons serve several template fields instead of duplicating a row per
 * field. Silently does nothing if focus isn't on a text field (e.g. the user
 * clicked a chip without having clicked into a field first).
 *
 * Callers must invoke this from a `mousedown` handler with preventDefault
 * (not `click`) -- a button's default mousedown behaviour shifts focus to
 * the button itself before a click handler would ever run, which would make
 * `document.activeElement` the button, not the field the user was editing.
 * @param {string} token
 */
window.insertAlertToken = (token) => {
    const el = document.activeElement;
    if (!el || (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA')) return;

    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? el.value.length;
    el.value = el.value.slice(0, start) + token + el.value.slice(end);
    el.selectionStart = el.selectionEnd = start + token.length;
    el.dispatchEvent(new Event('input', { bubbles: true }));
};
