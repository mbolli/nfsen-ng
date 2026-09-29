import { rocket } from 'datastar'

// One ticker for every instance on the page: each registers when its text
// next changes, and only those are recomputed.
const watchers = new Set()
let ticker = 0
const tick = () => {
	const now = Date.now()
	for (const w of watchers) if (w.next <= now) w.update(now)
}
const watch = (w) => {
	watchers.add(w)
	ticker ||= setInterval(tick, 1000)
	return () => {
		watchers.delete(w)
		if (!watchers.size) (clearInterval(ticker), (ticker = 0))
	}
}

const SECOND = 1000, MINUTE = 60 * SECOND, HOUR = 60 * MINUTE, DAY = 24 * HOUR, WEEK = 7 * DAY, YEAR = 365 * DAY, MONTH = YEAR / 12
const UNITS = [
	['year', YEAR],
	['month', MONTH],
	['week', WEEK],
	['day', DAY],
	['hour', HOUR],
	['minute', MINUTE],
	['second', SECOND],
]

// parse accepts ISO 8601 and Unix time (seconds or milliseconds). Four
// digits are a year, as in HTML's <time>.
const parse = (v) => {
	const s = String(v ?? '').trim()
	if (!s) return NaN
	if (/^(?!\d{4}$)-?\d+(\.\d+)?$/.test(s)) {
		const n = Number(s)
		return Math.abs(n) < 1e11 ? n * 1000 : n
	}
	return Date.parse(s)
}

// A malformed tag (en_US) would make Intl throw: repair it, or use the default.
const locale = (tag) => { try { return Intl.getCanonicalLocales(tag?.replace(/_/g, '-') || [])[0] } catch {} }

// A time zone Intl can't read falls back to the browser's (undefined).
const zone = (tz) => { try { return tz ? new Intl.DateTimeFormat('en', { timeZone: tz }).resolvedOptions().timeZone : undefined } catch {} }

// wall is t on the zone's clock, read as if that clock were UTC.
const clocks = new Map()
const wall = (t, tz) => {
	if (!tz) return t - new Date(t).getTimezoneOffset() * MINUTE
	let clock = clocks.get(tz)
	if (!clock) clocks.set(tz, (clock = new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' })))
	const f = {}
	for (const { type, value } of clock.formatToParts(t)) f[type] = +value
	return Date.UTC(f.year, f.month - 1, f.day, f.hour, f.minute, f.second, ((t % SECOND) + SECOND) % SECOND)
}

// The calendar date t falls on in the zone (days since 1970-01-01), and when the next one starts.
const day = (t, tz) => Math.floor(wall(t, tz) / DAY)
const nextDay = (t, tz) => {
	const d = day(t, tz) + 1
	const guess = t + d * DAY - wall(t, tz)
	let lo = guess + d * DAY - wall(guess, tz) // the zone's offset may change before midnight
	if (day(lo, tz) >= d) return lo
	// Midnight falls in a DST gap (America/Santiago): the date starts at the jump.
	let hi = guess
	while (day(hi, tz) < d) (lo = hi), (hi += HOUR)
	while (hi - lo > 1) {
		const mid = Math.floor((lo + hi) / 2)
		if (day(mid, tz) < d) lo = mid
		else hi = mid
	}
	return hi
}

const TITLES = {
	full: { dateStyle: 'full', timeStyle: 'short' },
	// the fields Date.prototype.toLocaleString() shows
	numeric: { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' },
}

// relative picks the largest unit that fits, and the moment the text next
// changes. That is an edge of the unit abs is in (before rounding up to the
// next one): the rounded count moves (abs crosses k + ½ units), or abs grows
// into the next unit (past) or shrinks below this one (future). Days count
// calendar dates in the zone, so they also change at its midnight.
const relative = (then, now, fmt, tz) => {
	const diff = then - now
	const abs = Math.abs(diff)
	const past = diff <= 0
	const i = abs < SECOND ? UNITS.length - 1 : Math.max(0, UNITS.findIndex(([, ms]) => abs >= ms))
	let [unit, ms] = UNITS[i]
	const edges = [
		Math.abs((Math[past ? 'ceil' : 'floor'](abs / ms - 0.5) + 0.5) * ms - abs) + 1,
		past ? UNITS[i - 1]?.[1] - abs : abs - ms + 1,
	]
	// "60 minutes ago" reads better as "1 hour ago".
	if (i && Math.abs(Math.round(diff / ms)) * ms >= UNITS[i - 1][1]) [unit, ms] = UNITS[i - 1]
	let n = Math.round(diff / ms)
	if (unit == 'day') {
		// "yesterday" is the date before today, not 24 hours ago
		n = day(then, tz) - day(now, tz) || diff * 0
		edges.push(nextDay(now, tz) - now)
	}
	return { text: fmt.format(n, unit), next: now + Math.min(...edges.filter((e) => e > 0)) }
}

rocket('sb-relative-time', {
	props: ({ bool, number, oneOf, string }) => ({
		datetime: string.trim.docs({ description: 'The moment: ISO 8601 (2026-09-22T08:00:00Z) or Unix time (seconds or ms).' }),
		numeric: oneOf('auto', 'always').default('auto').docs({ description: '"auto" allows words like "yesterday"; "always" says "1 day ago".' }),
		format: oneOf('long', 'short', 'narrow').default('long').docs({ description: 'Length of the units ("3 minutes" / "3 min." / "3m").' }),
		lang: string.trim.docs({ description: 'Locale (default: the page\'s lang, then the browser\'s).' }),
		threshold: number.min(0).docs({ description: 'After this many days, show the date instead (0: always relative).' }),
		sync: bool.default(true).docs({ description: 'Keep the text current while the page is open.' }),
		timeZone: string.trim.docs({ description: 'IANA time zone (Europe/Zurich) of the date on hover, the threshold date and the day count (default, and for a zone the browser can\'t read: the browser\'s).' }),
		titleLang: string.trim.docs({ description: 'Locale of the date on hover (default: the text\'s; "auto": the browser\'s).' }),
		titleStyle: oneOf('full', 'numeric').default('full').docs({ description: '"full" spells out the weekday and month; "numeric" shows what toLocaleString() does, seconds included.' }),
	}),
	// setup builds the <time> itself: a first render inside a server morph breaks that morph (datastar#1209).
	setup: ({ cleanup, host, observeProps, props }) => {
		const root = host.shadowRoot
		if (!root.firstChild) root.innerHTML = '<time part="time"></time>'
		const time = root.firstChild
		const w = {}
		w.update = (now = Date.now()) => {
			const then = parse(props.datetime)
			const date = new Date(then)
			if (isNaN(date)) {
				time.textContent = host.textContent.trim() // the server's fallback
				time.removeAttribute('datetime')
				time.removeAttribute('title')
				w.next = Infinity
				return
			}
			// the nearest lang, also outside the shadow roots of other components
			let el = host, l
			while (!(l = el.closest('[lang]')) && (el = el.getRootNode().host));
			const lang = locale(l?.lang)
			const tz = zone(props.timeZone)
			const titleLang = props.titleLang === 'auto' ? undefined : props.titleLang ? locale(props.titleLang) : lang
			time.dateTime = date.toISOString()
			time.title = new Intl.DateTimeFormat(titleLang, { ...TITLES[props.titleStyle], timeZone: tz }).format(date)
			const t = props.threshold * DAY
			let next
			if (t && Math.abs(then - now) > t) {
				time.textContent = new Intl.DateTimeFormat(lang, { dateStyle: props.format === 'long' ? 'long' : 'medium', timeZone: tz }).format(date)
				next = then > now ? then - t : Infinity // a future date turns relative, a past one stays
			} else {
				const r = relative(then, now, new Intl.RelativeTimeFormat(lang, { numeric: props.numeric, style: props.format }), tz)
				time.textContent = r.text
				next = Math.min(r.next, t ? then + t + 1 : Infinity) // a past moment turns into a date
			}
			w.next = props.sync ? next : Infinity
		}
		w.update()
		observeProps(() => w.update())
		cleanup(watch(w))
	},
})
