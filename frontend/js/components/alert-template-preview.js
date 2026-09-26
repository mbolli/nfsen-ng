/**
 * Alerts page helpers: the live preview of notification templates, the {token} buttons and
 * the rule form's focus. The preview mirrors AlertManager::buildTemplateVars() and
 * resolveTemplate() with example figures, so it works for an unsaved rule without a round
 * trip; its token list is the one buildTemplateVars() substitutes.
 */

const FAKE_METRICS = { flows: 42, packets: 3141, bytes: 123456.78 };

function fmt(n) {
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Build the {token: value} map for the preview. Without `sources` (the default templates) the
 * sources are an example; a rule with none selected shows `allSources`, the server's text for that.
 * @param {object} form - { rule, metric, operator, profile, sources, allSources, thresholdType, thresholdValue }
 * @returns {Record<string, string>}
 */
window.buildAlertPreviewVars = (form) => {
    const metric = form.metric || 'bytes';
    const operator = form.operator || '>';
    const thresholdValue = Number(form.thresholdValue) || 0;
    const thresholdDisplay = form.thresholdType === 'percent_of_avg' ? `${fmt(thresholdValue)}% of avg` : fmt(thresholdValue);
    let sources = 'gw1, gw2';
    if (Array.isArray(form.sources)) sources = form.sources.length ? form.sources.join(', ') : (form.allSources ?? '');

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

/** Replace the field's selection with the token and tell Datastar's bind about it. */
function insertToken(field, token) {
    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? field.value.length;
    field.value = field.value.slice(0, start) + token + field.value.slice(end);
    field.selectionStart = field.selectionEnd = start + token.length;
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

// The template field last edited in each [data-token-scope], the target of its token buttons.
const lastField = new WeakMap();

document.addEventListener('focusin', (event) => {
    const field = event.target.closest?.('[data-template-field]');
    const scope = field?.closest('[data-token-scope]');
    if (scope) lastField.set(scope, field);
});

// A mouse press on a token button keeps the caret in the field being edited.
document.addEventListener('mousedown', (event) => {
    if (event.target.closest?.('[data-alert-token]')) event.preventDefault();
});

document.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-alert-token]');
    const scope = button?.closest('[data-token-scope]');
    if (!scope) return;
    const active = document.activeElement;
    const field =
        (active?.matches('[data-template-field]') && scope.contains(active) ? active : null) ??
        lastField.get(scope) ??
        scope.querySelector('[data-template-field]');
    if (!field) return;
    insertToken(field, button.dataset.alertToken);
    field.focus();
});

window.alertForm = {
    /** After Edit or New rule: open the template overrides the rule uses, then bring the form into view. */
    open() {
        requestAnimationFrame(() => {
            const card = document.getElementById('alert-form-card');
            if (!card) return;
            for (const details of card.querySelectorAll('details.template-override')) {
                details.open = [...details.querySelectorAll('[data-template-field]')].some((f) => f.value !== '');
            }
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            card.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            document.getElementById('alertFormName')?.focus({ preventScroll: true });
        });
    },
};
