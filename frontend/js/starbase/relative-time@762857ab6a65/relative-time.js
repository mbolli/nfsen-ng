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

const midnight = (t, h = 0) => new Date(t).setHours(h, 0, 0, 0)

// relative picks the largest unit that fits, and the moment the text next
// changes. That is an edge of the unit abs is in (before rounding up to the
// next one): the rounded count moves (abs crosses k + ½ units), or abs grows
// into the next unit (past) or shrinks below this one (future). Days count
// calendar dates, so they also change at midnight.
const relative = (then, now, fmt) => {
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
		n = Math.round((midnight(then) - midnight(now)) / DAY) || diff * 0
		edges.push(midnight(now, 24) - now)
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
	}),
	renderOnPropChange: false,
	setup: ({ $$, cleanup, host, observeProps, props }) => {
		const w = {}
		w.update = (now = Date.now()) => {
			const then = parse(props.datetime)
			const date = new Date(then)
			if (isNaN(date)) {
				$$.text = host.textContent.trim() // the server's fallback
				$$.iso = $$.title = ''
				w.next = Infinity
				return
			}
			// the nearest lang, also outside the shadow roots of other components
			let el = host, l
			while (!(l = el.closest('[lang]')) && (el = el.getRootNode().host));
			const lang = locale(l?.lang)
			$$.iso = date.toISOString()
			$$.title = new Intl.DateTimeFormat(lang, { dateStyle: 'full', timeStyle: 'short' }).format(date)
			const t = props.threshold * DAY
			let next
			if (t && Math.abs(then - now) > t) {
				$$.text = new Intl.DateTimeFormat(lang, { dateStyle: props.format === 'long' ? 'long' : 'medium' }).format(date)
				next = then > now ? then - t : Infinity // a future date turns relative, a past one stays
			} else {
				const r = relative(then, now, new Intl.RelativeTimeFormat(lang, { numeric: props.numeric, style: props.format }))
				$$.text = r.text
				next = Math.min(r.next, t ? then + t + 1 : Infinity) // a past moment turns into a date
			}
			w.next = props.sync ? next : Infinity
		}
		w.update()
		observeProps(() => w.update())
		cleanup(watch(w))
	},
	render: ({ html }) => html`<time part="time" data-attr:datetime="$$iso || null" data-attr:title="$$title || null" data-text="$$text"></time>`,
})
