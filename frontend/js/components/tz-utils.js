/**
 * Timezone utilities for nfsen-ng date display.
 *
 * PHP and the browser Intl API both use IANA timezone identifiers
 * (e.g. "Europe/Zurich", "UTC"), so no mapping is needed.
 * The only guard required is against non-IANA abbreviations like "CET"
 * that PHP may return from date_default_timezone_get() on some systems —
 * the browser Intl API rejects those and would throw a RangeError.
 */

/**
 * Build an Intl options object for toLocaleString / Intl.DateTimeFormat.
 *
 * @param {string} displayTz  - "browser" or "server"
 * @param {string} serverTz   - IANA timezone string from the server (e.g. "Europe/Zurich")
 * @returns {Intl.DateTimeFormatOptions} options object (may be empty for browser mode or invalid IANA names)
 */
export function tzOptions(displayTz, serverTz) {
    if (displayTz !== 'server') {
        return {};
    }
    try {
        // Validate that serverTz is an IANA identifier the browser accepts.
        // Throws RangeError for abbreviations like "CET", "EST", etc.
        Intl.DateTimeFormat(undefined, { timeZone: serverTz });
        return { timeZone: serverTz };
    } catch {
        // Invalid IANA name — fall back to browser local time silently.
        return {};
    }
}

/**
 * Format a Date object as a locale string with optional server timezone.
 *
 * @param {Date|number} d        - Date object or millisecond timestamp
 * @param {string}      displayTz - "browser" or "server"
 * @param {string}      serverTz  - IANA timezone string
 * @param {Intl.DateTimeFormatOptions} [extra] - Additional Intl options (e.g. timeStyle)
 * @returns {string}
 */
export function formatDate(d, displayTz, serverTz, extra = {}) {
    const date = d instanceof Date ? d : new Date(d);
    return date.toLocaleString(undefined, { ...tzOptions(displayTz, serverTz), ...extra });
}

/**
 * Get hours for a Date in the given timezone context.
 * Falls back to local time if serverTz is invalid.
 *
 * @param {Date}   d
 * @param {string} displayTz
 * @param {string} serverTz
 * @returns {number}
 */
export function getHours(d, displayTz, serverTz) {
    const opts = tzOptions(displayTz, serverTz);
    if (!opts.timeZone) return d.getHours();
    return parseInt(new Intl.DateTimeFormat(undefined, { hour: 'numeric', hourCycle: 'h23', timeZone: opts.timeZone }).format(d), 10);
}

/**
 * Get minutes for a Date in the given timezone context.
 *
 * @param {Date}   d
 * @param {string} displayTz
 * @param {string} serverTz
 * @returns {number}
 */
export function getMinutes(d, displayTz, serverTz) {
    const opts = tzOptions(displayTz, serverTz);
    if (!opts.timeZone) return d.getMinutes();
    return parseInt(new Intl.DateTimeFormat(undefined, { minute: 'numeric', timeZone: opts.timeZone }).format(d), 10);
}

/**
 * Format a Date as a short date string (e.g. "07 May" or "07 May '26") for slider pips.
 * Uses the user's locale rather than hardcoding 'en-GB'.
 *
 * @param {Date}   d
 * @param {string} displayTz
 * @param {string} serverTz
 * @param {object} opts      - Intl.DateTimeFormat date part options
 * @returns {string}
 */
export function formatDatePart(d, displayTz, serverTz, opts) {
    const tzOpts = tzOptions(displayTz, serverTz);
    return new Intl.DateTimeFormat(undefined, { ...tzOpts, ...opts }).format(d);
}
