/**
 * Tiny hash-based router.
 *
 * Routes:
 *   #/                       → round picker (default)
 *   #/round/:id              → plot picker for that round
 *   #/round/:id/plot/:plotId → finding editor
 *   #/round/:id/plot/:plotId/follow-up → finding editor, recording visit 2
 *
 * Renders into a #app element via a `mount(view)` API. Each view module
 * exports a `render(params, ctx) → HTMLElement` function and optionally
 * a `cleanup()` returned by render for teardown.
 */

const routes = [
	{ pattern: /^#?\/?$/,                                                  name: 'roundPicker' },
	{ pattern: /^#?\/?queue\/?$/,                                          name: 'queue' },
	{ pattern: /^#?\/?round\/(\d+)\/?$/,                                   name: 'plotPicker',    params: ['roundId'] },
	{ pattern: /^#?\/?round\/(\d+)\/plot\/(\d+)\/?$/,                      name: 'findingEditor', params: ['roundId', 'plotId'] },
	// A re-inspection is visit 2 of the SAME round (#883), so it is the same
	// editor on the same plot — a mode, not a screen. Giving it a route rather
	// than a variable means Back returns to the recorded result instead of
	// leaving the round.
	{ pattern: /^#?\/?round\/(\d+)\/plot\/(\d+)\/(follow-up)\/?$/,        name: 'findingEditor', params: ['roundId', 'plotId', 'mode'] },
];

let currentCleanup = null;
let viewsRegistry = {};
let mountEl = null;

export function registerViews(views) {
	viewsRegistry = views;
}

export function start(rootEl) {
	mountEl = rootEl;
	window.addEventListener('hashchange', render);
	render();
}

export function navigate(hash) {
	window.location.hash = hash.startsWith('#') ? hash : ('#' + (hash.startsWith('/') ? hash : '/' + hash));
}

/**
 * Which view a hash resolves to, and with what params.
 *
 * Exported and pure so the route table can be tested without a DOM. A route
 * that silently stops matching takes its whole screen out of the app while
 * every module behind it still loads and passes — the same shape of loss as
 * ams#886, where a working endpoint had nothing left that could reach it.
 *
 * @param {string} hash Location hash, e.g. '#/round/3/plot/7/follow-up'.
 * @returns {{name: string, params: Object}}
 */
export function matchRoute(hash) {
	const h = hash || '#/';
	for (const r of routes) {
		const m = h.match(r.pattern);
		if (m) {
			const params = {};
			(r.params || []).forEach((name, i) => { params[name] = m[i + 1]; });
			return { name: r.name, params };
		}
	}
	return { name: 'notFound', params: {} };
}

function parseRoute() {
	return matchRoute(window.location.hash || '#/');
}

async function render() {
	if (typeof currentCleanup === 'function') {
		try { currentCleanup(); } catch (e) { /* swallow */ }
		currentCleanup = null;
	}
	const { name, params } = parseRoute();
	const view = viewsRegistry[name] || viewsRegistry.notFound;
	if (!view) return;
	try {
		const result = await view.render(params, { mount: mountEl, navigate });
		if (typeof result === 'function') currentCleanup = result;
	} catch (e) {
		console.error('Render failed', e);
		mountEl.innerHTML = `<div class="ami-main"><div class="ami-error">${e.message || 'Failed to load'}</div></div>`;
	}
	window.scrollTo(0, 0);
}
