/**
 * Inspector PWA service worker.
 *
 * Pre-caches the app shell, CSS, JS modules and manifest on install. Runtime
 * caches API responses (network-first with cache fallback) and map tiles
 * (stale-while-revalidate, bounded). On navigation requests within
 * /inspect/, falls back to the cached shell so the app boots offline.
 */

// Bump on any shipped JS/CSS/HTML change so already-installed PWAs
// evict their pre-cached shell and pick up the new bytes on next load.
// 'v2' adds the issue-tickbox UI (finding-editor) + the api/sync/store
// pass-through for the 2.11.2 fields.
// 'v3' adds the plot Map view (plot-map.js) + the bundled Leaflet library,
// precached so the map works offline.
// 'v4' adds the shared badge component (js/components/badge.js).
// 'v5' map honours the tile provider's maxNativeZoom (upscale past Esri's z19
// instead of showing "Map data not yet available" when zooming in).
// 'v6' map draws plot footprints (rotated rectangles that scale with the map)
// instead of fixed-size dots.
// 'v7' plot number + tenant are permanent labels on the map, not hover-only.
// 'v8' plot labels are rotated to run along each plot (admin-map style) instead
// of horizontal chips that collide.
// 'v9' fix label rotation: out-specify leaflet.css's `.leaflet-marker-icon
// { display:block }` so the label stays a flex/inline-block element that CSS
// transform actually applies to (transform is ignored on display:inline).
// 'v10' /code-review polish: finite-rotation guard, hoist escape map, defer
// popup DOM.
// 'v11' findings now POST to the inspections plugin's own save endpoint (so
// they actually sync); map remembers position; labels scale with zoom.
// 'v12' app.js auto-reloads on SW update (controllerchange) so a deploy applies
// in one reload instead of two.
// 'v13' gentler map-label scaling (was ballooning when zoomed in).
// 'v14' POST to admin-ajax on the CURRENT origin (a cached cross-origin/stale
// ajaxUrl was silently failing writes while cached reads still worked).
// 'v15' finding editor: delete a just-added photo; bottom padding clears the
// fixed Save bar so the last tickbox (tenancy breach) is reachable.
// 'v16' sync failures are now visible: the header pill turns red and tapping it
// shows WHY (incl. the host it tried), instead of silently staying queued.
// 'v17' tap the pill for a full status report (build + queued findings/photos +
// last error); shell now sends no-cache so devices stop pinning to old builds.
// 'v18' show the build number in the header (after the title) so a stale cached
// copy is obvious at a glance.
// 'v19' rating-only findings (no typed notes) now save — the server fills in a
// summary from the rating + ticked issues instead of rejecting them.
// 'v20' tap the pill to open a Sync queue view: inspect what's waiting, retry,
// or delete an item that's blocking the queue.
// 'v25' field-usability pass: vacant plots shown-but-not-inspectable, new
// tenants flagged exempt, map→save returns to the Map tab, and photos upload in
// the background (never block save) with a "Wi-Fi only" setting (adds net.js).
// 'v26' committee exemption / internal-review: inspector can mark a plot Exempt
// or Internal review with a committee-only note (db 2.20.0).
// 'v27' label the Notes field as shown-to-the-member + factual placeholder.
// 'v28' sync no longer wedges: a finding the server permanently rejects (e.g.
// the inspector's OWN plot) is skipped-and-surfaced instead of freezing every
// good finding behind it; the editor blocks recording your own plot up front.
// 'v29' a follow-up round lists the WHOLE section, with the plots that passed
// the first round faded and not inspectable — walking from V3 to V47 past forty
// plots that were not on the list made the round hard to navigate. Progress
// still counts only the plots being re-inspected.
// 'v30' the plot list and map can be filtered by the round's verdicts — the
// same buckets the committee's own round screen filters by, so "show me the
// non-compliant plots" answers the same on a phone in the field as it does at
// the desk. The list also reads compliance_status now, so an exempt or
// under-review plot no longer renders as "Not inspected".
// 'v31' the round-type chip ("First round", on every card and round header) is
// gone: ams#883 made a round one per (year, site), list_rounds stopped
// returning inspectionType, and the label had been defaulting to a distinction
// that no longer exists. The faded out-of-scope rendering went with it.
// 'v32' the map AND the badges are coloured by compliance status, not
// cultivation category: light green passed, light red non-compliant, light
// blue exempt. Same axis the filter chips use, so filtering to Non-compliant
// leaves exactly the red plots on screen, and a plot's polygon and its badge
// can no longer disagree. The legend lists only the colours actually present.
// 'v33' a round's plots can be searched by plot number or tenant name, from
// one box that feeds both the List and the Map (adds js/services/plot-search.js).
// The Map opens on the lowest-numbered match, so searching a tenant who holds
// B15, B17 and B19 lands on B15.
// 'v34' a re-inspection can be recorded in the field. A plot that already had
// a finding always opened for EDIT, so the only thing the app could do to an
// inspected plot was overwrite it — the result the notice was served on — while
// showing the inspector the work order and inviting them to record against it.
// Visit 2 is now its own route (/round/:r/plot/:p/follow-up), the editor names
// which visit it is showing, and the editor opens the LATEST visit rather than
// whichever row the database happened to return first.
const VERSION = 'v34';
const SHELL_CACHE = `ami-shell-${VERSION}`;
const RUNTIME_CACHE = `ami-runtime-${VERSION}`;
const TILE_CACHE = `ami-tiles-${VERSION}`;

const PLUGIN_BASE = new URL(self.location.href).pathname.replace(/service-worker\.js.*$/, ''); // .../public/

// Pre-cache the app shell + critical static assets.
const PRECACHE_URLS = [
	'/inspect/',
	`${PLUGIN_BASE}css/inspect.css`,
	`${PLUGIN_BASE}js/app.js`,
	`${PLUGIN_BASE}js/router.js`,
	`${PLUGIN_BASE}js/components/header.js`,
	`${PLUGIN_BASE}js/components/badge.js`,
	`${PLUGIN_BASE}js/pages/round-picker.js`,
	`${PLUGIN_BASE}js/pages/plot-picker.js`,
	`${PLUGIN_BASE}js/pages/plot-map.js`,
	`${PLUGIN_BASE}js/pages/finding-editor.js`,
	`${PLUGIN_BASE}js/pages/queue.js`,
	`${PLUGIN_BASE}js/services/api.js`,
	`${PLUGIN_BASE}js/services/store.js`,
	`${PLUGIN_BASE}js/services/sync.js`,
	`${PLUGIN_BASE}js/services/net.js`,
	`${PLUGIN_BASE}js/services/plot-search.js`,
	`${PLUGIN_BASE}vendor/leaflet/leaflet.js`,
	`${PLUGIN_BASE}vendor/leaflet/leaflet.css`,
	`${PLUGIN_BASE}manifest.webmanifest`,
	`${PLUGIN_BASE}icons/icon-192.png`,
	`${PLUGIN_BASE}icons/icon-512.png`,
];

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches.open(SHELL_CACHE)
			.then((cache) => cache.addAll(PRECACHE_URLS).catch((e) => {
				// Don't fail install if a precache fetch fails — assets will still
				// be fetched on first navigation.
				console.warn('SW precache had errors', e);
			}))
			.then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys()
			.then((keys) => Promise.all(keys
				.filter((k) => k.startsWith('ami-') && !k.endsWith(VERSION))
				.map((k) => caches.delete(k))
			))
			.then(() => self.clients.claim())
	);
});

self.addEventListener('fetch', (event) => {
	const req = event.request;
	if (req.method !== 'GET') return;

	const url = new URL(req.url);

	// Navigation: always serve the cached shell so the app can boot offline,
	// then router/client code re-fetches data.
	if (req.mode === 'navigate' && url.pathname.startsWith('/inspect')) {
		event.respondWith(
			(async () => {
				try {
					const fresh = await fetch(req);
					const cache = await caches.open(SHELL_CACHE);
					cache.put('/inspect/', fresh.clone());
					return fresh;
				} catch {
					const cached = await caches.match('/inspect/');
					return cached || new Response('Offline', { status: 503, statusText: 'Offline' });
				}
			})()
		);
		return;
	}

	// admin-ajax.php — network-first with cache fallback for GETs only.
	if (url.pathname.endsWith('/wp-admin/admin-ajax.php') && req.method === 'GET') {
		event.respondWith(
			(async () => {
				try {
					const fresh = await fetch(req);
					const cache = await caches.open(RUNTIME_CACHE);
					cache.put(req, fresh.clone());
					return fresh;
				} catch {
					const cached = await caches.match(req);
					return cached || new Response(JSON.stringify({ success: false, data: { message: 'Offline' } }), {
						status: 503,
						headers: { 'Content-Type': 'application/json' },
					});
				}
			})()
		);
		return;
	}

	// Map tiles (Leaflet / OSM / Esri): stale-while-revalidate, bounded cache.
	if (/(\.png|\.jpg|\.jpeg|\.webp)(\?|$)/.test(url.pathname) && /tile/i.test(url.hostname + url.pathname)) {
		event.respondWith(
			(async () => {
				const cache = await caches.open(TILE_CACHE);
				const cached = await cache.match(req);
				const fetchPromise = fetch(req).then((res) => {
					if (res.ok) cache.put(req, res.clone());
					return res;
				}).catch(() => cached);
				return cached || fetchPromise;
			})()
		);
		return;
	}

	// Plugin static assets: cache-first.
	if (url.pathname.includes('/wp-content/plugins/allotment-manager-inspections/')) {
		event.respondWith(
			caches.match(req).then((cached) => cached || fetch(req).then((res) => {
				if (res.ok) {
					caches.open(SHELL_CACHE).then((cache) => cache.put(req, res.clone()));
				}
				return res;
			}))
		);
		return;
	}
});
