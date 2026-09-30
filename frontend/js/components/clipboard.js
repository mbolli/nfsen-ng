/**
 * Copying text, also over plain HTTP (nfsen/clipboard). A `button[data-copy-source]` copies the
 * element with that id: data-copy-mode text (default), rows (data-copy-rows) or loaded.
 */
import { whenLoaded } from 'nfsen/chunks';

const FLASH_MS = 1500;
const flashes = new WeakMap();
const clearTimers = new WeakMap();

/** A modal dialog makes the rest of the page inert, so its own regions and body are used. */
const openModal = () => [...document.querySelectorAll('dialog:modal')].pop() ?? null;

/** Copies `text`; false when both the Clipboard API and the textarea fallback failed. */
export async function copyText(text) {
    const value = String(text ?? '');
    if (window.isSecureContext && navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch {
            // Refused, for example while the document has no focus: the fallback may still work.
        }
    }
    const previous = document.activeElement;
    const area = document.createElement('textarea');
    area.value = value;
    area.readOnly = true;
    area.setAttribute('aria-hidden', 'true');
    area.style.cssText = 'position:fixed;inset-block-start:0;opacity:0';
    (openModal() ?? document.body).append(area);
    area.select();
    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }
    area.remove();
    if (previous instanceof HTMLElement && previous !== document.body) previous.focus({ preventScroll: true });
    return copied;
}

/**
 * Says `message` in the open modal's `[data-announcer]` region, else in #nfsen-announcer. The
 * region is emptied first and again later, so the same message twice is announced twice.
 */
export function announce(message) {
    const region = openModal()?.querySelector('[data-announcer]') ?? document.getElementById('nfsen-announcer');
    if (!region) return;
    clearTimeout(clearTimers.get(region));
    region.textContent = '';
    requestAnimationFrame(() => {
        clearTimeout(clearTimers.get(region));
        region.textContent = message;
        clearTimers.set(
            region,
            setTimeout(() => {
                region.textContent = '';
            }, FLASH_MS)
        );
    });
}

/** Shows `text` on the button for a moment, then its own label again. */
function flash(button, text) {
    const shown = flashes.get(button);
    const label = shown?.label ?? button.textContent;
    clearTimeout(shown?.timer);
    button.textContent = text;
    const timer = setTimeout(() => {
        button.textContent = label;
        flashes.delete(button);
    }, FLASH_MS);
    flashes.set(button, { label, timer });
}

/** The text a copy button copies, or null when its source is missing. */
async function sourceText(button) {
    const source = document.getElementById(button.dataset.copySource);
    if (!source) return null;
    if (button.dataset.copyMode === 'rows') {
        return [...source.querySelectorAll(button.dataset.copyRows || 'tbody tr:not([data-empty])')]
            .filter((row) => row.getClientRects().length > 0)
            .map((row) => [...(row.cells ?? row.children)].map((cell) => cell.textContent.trim()).join('  '))
            .join('\n');
    }
    if (button.dataset.copyMode === 'loaded') {
        return (await whenLoaded(source)).textContent;
    }
    return source.textContent;
}

document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('button[data-copy-source]') : null;
    if (!button || button.disabled) return;
    const text = await sourceText(button);
    const blank = text !== null && text.trim() === '';
    const ok = text !== null && !blank && (await copyText(text));
    const [label, message] = ok
        ? ['Copied', button.dataset.copyAnnounce ?? 'Copied.']
        : blank
          ? ['Nothing to copy', 'Nothing to copy.']
          : ['Copy failed', 'Copy failed.'];
    // A button with a [data-text] label shows detail.label through its own signal.
    if (!button.querySelector('[data-text]')) flash(button, label);
    announce(message);
    button.dispatchEvent(new CustomEvent('nfsen-copy', { bubbles: true, detail: { ok, text: text ?? '', label } }));
});
