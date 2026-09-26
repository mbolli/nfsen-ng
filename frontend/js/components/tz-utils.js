/**
 * Dates in the user's display timezone: formatting, and reading back what they typed.
 *
 * "server" display means the timezone nfcapd names its files in. PHP and Intl share IANA names;
 * one Intl rejects (an abbreviation such as "CET") falls back to the browser's own timezone.
 * Templates reach these through window.nfsenTime.
 */

/**
 * Intl options for toLocaleString / Intl.DateTimeFormat.
 *
 * @param {string} displayTz - "browser" or "server"
 * @param {string} serverTz  - IANA name of the server side (e.g. "Europe/Zurich")
 * @returns {Intl.DateTimeFormatOptions} empty for browser time
 */
export function tzOptions(displayTz, serverTz) {
    if (displayTz !== 'server') {
        return {};
    }
    try {
        Intl.DateTimeFormat(undefined, { timeZone: serverTz });
        return { timeZone: serverTz };
    } catch {
        return {};
    }
}

/**
 * A date as a locale string in the display timezone.
 *
 * @param {Date|number} d - Date or milliseconds
 * @param {string} displayTz
 * @param {string} serverTz
 * @param {Intl.DateTimeFormatOptions} [extra]
 * @returns {string}
 */
export function formatDate(d, displayTz, serverTz, extra = {}) {
    const date = d instanceof Date ? d : new Date(d);
    return date.toLocaleString(undefined, { ...tzOptions(displayTz, serverTz), ...extra });
}

const pad = (n, width = 2) => String(n).padStart(width, '0');

/** Wall-clock fields of `ms` in `zone`, or in the browser's timezone when `zone` is empty. */
function wallClock(ms, zone) {
    if (!zone) {
        const d = new Date(ms);
        return {
            year: d.getFullYear(),
            month: d.getMonth() + 1,
            day: d.getDate(),
            hour: d.getHours(),
            minute: d.getMinutes(),
            second: d.getSeconds(),
        };
    }
    const fields = {};
    const format = new Intl.DateTimeFormat('en-US', {
        timeZone: zone,
        hourCycle: 'h23',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        second: 'numeric',
    });
    for (const { type, value } of format.formatToParts(new Date(ms))) {
        if (type !== 'literal') fields[type] = Number(value);
    }
    return fields;
}

/** How far `zone` is ahead of UTC at the instant `ms`, in milliseconds. */
function zoneOffset(ms, zone) {
    const w = wallClock(ms, zone);
    return Date.UTC(w.year, w.month - 1, w.day, w.hour, w.minute, w.second) - Math.floor(ms / 1000) * 1000;
}

/**
 * The value of a datetime-local input ("YYYY-MM-DDTHH:MM") showing `epoch` in the display timezone.
 *
 * @param {number} epoch - seconds
 * @param {string} displayTz
 * @param {string} serverTz
 * @returns {string}
 */
export function toLocalInput(epoch, displayTz, serverTz) {
    const w = wallClock(epoch * 1000, tzOptions(displayTz, serverTz).timeZone ?? '');
    return `${pad(w.year, 4)}-${pad(w.month)}-${pad(w.day)}T${pad(w.hour)}:${pad(w.minute)}`;
}

/**
 * Epoch seconds of a datetime-local value read as wall-clock time in the display timezone.
 * A time a DST change skips resolves to the same wall time after the change.
 *
 * @param {string} localString - "YYYY-MM-DDTHH:MM" or with ":SS"
 * @param {string} displayTz
 * @param {string} serverTz
 * @returns {number} NaN when the value is not a date and time
 */
export function toEpoch(localString, displayTz, serverTz) {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(localString ?? '').trim());
    if (!m) return Number.NaN;
    const [year, month, day, hour, minute] = m.slice(1, 6).map(Number);
    const second = Number(m[6] ?? 0);
    const zone = tzOptions(displayTz, serverTz).timeZone ?? '';
    if (!zone) {
        return Math.floor(new Date(year, month - 1, day, hour, minute, second).getTime() / 1000);
    }
    const wall = Date.UTC(year, month - 1, day, hour, minute, second);
    // The offset at a first guess, then at the corrected instant, which settles a DST edge.
    const guess = wall - zoneOffset(wall, zone);
    return Math.floor((wall - zoneOffset(guess, zone)) / 1000);
}

/**
 * "Sep 24, 14:20 to Sep 25, 14:20" in the display timezone, with the year when the range is
 * not all in the current year.
 *
 * @param {number} from - seconds
 * @param {number} to - seconds
 * @param {string} displayTz
 * @param {string} serverTz
 * @returns {string}
 */
export function formatRange(from, to, displayTz, serverTz) {
    if (!Number.isFinite(from) || !Number.isFinite(to)) return '';
    const options = tzOptions(displayTz, serverTz);
    const zone = options.timeZone ?? '';
    const year = (ms) => wallClock(ms, zone).year;
    const thisYear = year(Date.now());
    const withYear = year(from * 1000) !== thisYear || year(to * 1000) !== thisYear;
    const format = new Intl.DateTimeFormat(undefined, {
        ...options,
        ...(withYear ? { year: 'numeric' } : {}),
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    return `${format.format(from * 1000)} to ${format.format(to * 1000)}`;
}

/**
 * A window width in words: "45 minutes", "24 hours", "6 days 4 hours", "2 weeks".
 *
 * @param {number} seconds
 * @returns {string}
 */
export function formatDuration(seconds) {
    const s = Math.max(0, Math.round(seconds));
    const unit = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
    const both = (a, aWord, b, bWord) => (b ? `${unit(a, aWord)} ${unit(b, bWord)}` : unit(a, aWord));
    if (s >= 14 * 86400 && s % (7 * 86400) === 0) return unit(s / (7 * 86400), 'week');
    if (s >= 2 * 86400) return both(Math.floor(s / 86400), 'day', Math.floor((s % 86400) / 3600), 'hour');
    if (s >= 3600) return both(Math.floor(s / 3600), 'hour', Math.floor((s % 3600) / 60), 'minute');
    return unit(Math.max(1, Math.round(s / 60)), 'minute');
}

/**
 * The range menu's toggle text: the preset's label while live, "Last <width>" for another
 * live window, and the bare width for a fixed one (the absolute display says where).
 *
 * @param {string} preset - range_preset
 * @param {boolean} live - range_live
 * @param {number} from - seconds
 * @param {number} to - seconds
 * @param {Record<string, string>} labels - preset id to label
 * @returns {string}
 */
export function rangeLabel(preset, live, from, to, labels) {
    if (live && labels?.[preset]) return labels[preset];
    const width = formatDuration(to - from);
    return live ? `Last ${width}` : width;
}

/**
 * The set-range query that restores a remembered window `{from, to, live, preset}`. A live one
 * comes back live: as its preset, as a duration when the width is whole weeks, days or hours,
 * else as the same width ending now. A fixed one comes back as the absolute range.
 *
 * @param {{from: number, to: number, live: boolean, preset: string}|null} previous
 * @param {number} [now] - seconds
 * @returns {string} '' when there is nothing to restore
 */
export function restoreQuery(previous, now = Math.floor(Date.now() / 1000)) {
    if (!previous || !Number.isFinite(previous.from) || !Number.isFinite(previous.to)) return '';
    if (!previous.live) return `?op=abs&from=${Math.floor(previous.from)}&to=${Math.ceil(previous.to)}`;
    if (previous.preset && previous.preset !== 'custom') return `?op=preset&v=${encodeURIComponent(previous.preset)}`;
    const width = Math.max(1, Math.round(previous.to - previous.from));
    for (const [u, seconds] of [
        ['w', 604800],
        ['d', 86400],
        ['h', 3600],
    ]) {
        const n = width / seconds;
        if (Number.isInteger(n) && n <= 9999) return `?op=duration&n=${n}&u=${u}`;
    }
    // set-range treats an end within 5 minutes of now as now, so this window is live again.
    return `?op=abs&from=${now - width}&to=${now}`;
}

window.nfsenTime = { tzOptions, formatDate, toLocalInput, toEpoch, formatRange, formatDuration, rangeLabel, restoreQuery };
