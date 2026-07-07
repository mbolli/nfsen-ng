/**
 * Custom Datastar attribute: `data-persist="{_signalA: $_signalA, _signalB: $_signalB}"`.
 *
 * Restores each named signal from localStorage once on load (unconditional
 * set, so a stored value always wins over whatever default a
 * `data-signals__ifmissing` block on the same element assigned — order
 * between the two doesn't matter), then keeps it saved on every change.
 *
 * Deliberately uses the plain *value* form (one attribute, an object-literal
 * expression), not `data-persist:_signalName="..."` (colon-key form): HTML
 * attribute *names* are lowercased by the parser, so a colon-key of
 * `_darkMode` silently arrives as `_darkmode` — a different, bogus signal
 * path. Attribute *values* preserve case, so the signal names belong there.
 *
 * Imports 'datastar' via the bare specifier (see the import map in
 * layout.html.twig), not a relative `../datastar.js` path — a relative import
 * resolves to a URL without the main script tag's `?v=` cache-busting query
 * string, which the module loader treats as a second, entirely separate
 * Datastar instance (its own signal store, its own MutationObserver on
 * document.documentElement watching the same DOM as the first). That was the
 * actual cause of the page hanging in earlier versions of this file, not
 * anything about effect()/rx() usage. Mirrors mbolli/datastar-attribute-prop.
 */
import { attribute, effect, mergePaths } from 'datastar';

const persistKey = (name) => `nfsen-persist:${name}`;

attribute({
    name: 'persist',
    requirement: { value: 'must' },
    returnsValue: true,
    apply({ rx }) {
        const initial = rx();
        for (const name of Object.keys(initial)) {
            const stored = localStorage.getItem(persistKey(name));
            if (stored !== null) {
                mergePaths([[name, JSON.parse(stored)]]);
            }
        }

        return effect(() => {
            const values = rx();
            for (const name of Object.keys(values)) {
                localStorage.setItem(persistKey(name), JSON.stringify(values[name]));
            }
        });
    },
});
