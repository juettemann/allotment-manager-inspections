=== Allotment Manager - Field Inspector ===

Contributors: juettemann
Tags: allotment, inspection, pwa, mobile
Requires at least: 6.7
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A mobile-first Progressive Web App for committee members to record plot inspections in the field.

== Description ==

Field Inspector is an add-on to the main Allotment Manager plugin. It exposes a phone- and tablet-friendly inspection tool at `/inspect/` that committee members use while walking the site to record plot ratings, notes and photos.

Features:

* Mobile-first UI with large tap targets
* Three-rating system: Pass / Minor / Major
* Camera-driven photo capture (uses native `<input capture>`)
* Works offline (Service Worker + IndexedDB queue) with automatic sync when back online
* Map view of the plots (Leaflet, reuses main-plugin tile config)
* Round-aware: first round shows every plot in the section; follow-up round shows only plots rated 2 or 3 in the parent round
* Capability-gated: only users with `am_field_inspector` can access
* Add to Home Screen as a PWA

== Dependencies ==

This plugin assumes the main `allotment-manager` plugin is installed and active (v2.2.0 or later). It reuses:

* `Inspection_Service::record_plot_inspection()` for saving findings
* `am_inspection_upload_photo` AJAX endpoint for photo uploads to Google Drive
* `Plot_Repository::get_by_section()` for plot listings
* `wp_am_map_objects` for plot polygons

== Installation ==

1. Activate the Allotment Manager plugin (parent dependency).
2. Activate this plugin. On activation, the `am_field_inspector` capability is granted to committee roles.
3. Visit `/inspect/` while logged in as a committee member.

== Changelog ==

= 1.8.0 =
* A round's plots can now be searched, from one box above the filter chips. Type a plot number or a tenant's name; the List narrows to the matches and the Map shows the same set, so the two never disagree about what you asked for.
* On the Map, a search opens on the lowest-numbered match. Searching a tenant who holds B15, B17 and B19 lands on B15, which is where you start walking.
* The search and the verdict chips compose: search a tenant, then tick Non-compliant, to get just their plots that need seeing. The chip counts follow the search, so they never advertise plots the search has excluded.
* Your search is remembered per round, like the chips already were, so recording a finding and coming back does not mean typing it again.

= 1.7.0 =
* The map now colours plots by their compliance status: light green passed, light red non-compliant, light blue exempt (including a new tenant inside the grace period, which the system exempts automatically). Under review stays amber, not yet inspected stays grey.
* Previously the map coloured by cultivation category, so a well-kept plot failed for rubbish or a derelict shed drew green while its own popup said Non-compliant. Cat 2 and Cat 3 are one red on the map now — at a glance the question is who you need to see; the popup and the list still tell you how bad it was.
* The badges on the plot list and in the map popups now use those same three colours, so a plot's colour on the map and its badge always agree. A Cat 2 and a Cat 3 both read red and still say "Cat 2" or "Cat 3" — the colour tells you who needs seeing, the label tells you how bad it was.
* The map key lists only the colours actually on screen, instead of naming states no plot in the round is in.

= 1.6.1 =
* Round cards and the round header no longer carry a "First round" tag. A round covers a whole section for a season now, so there is no first-versus-follow-up distinction left for it to draw — it had been showing "First round" on every round regardless.
* Removed the faded "passed the first round" rows from the plot list and map. They belonged to the retired follow-up rounds; every plot in a round is inspectable, so nothing rendered that way any more.

= 1.6.0 =
* Filter a round's plot list. A row of chips above the list — Non-compliant, Pass, Exempt, New tenant, Under review, Not inspected — narrows both the List and the Map to the plots you want; tap more than one to combine them, or "All" to clear. Your choice is remembered per round, so recording a finding and coming back keeps the filter you were working to. The buckets are the ones the committee's round screen on the website uses, so both read the same.
* A plot the committee exempted or put under review no longer shows as "Not inspected" — it now carries its own badge, on the list and on the map.
* Fix: a plot failed for rubbish, a derelict shed or a tenancy breach while still being well cultivated is Category 1 AND non-compliant. The list badged it "Pass" and the map drew it green. It now reads Non-compliant on both.
* Fix: the version shown in the app header (and on the Sync queue screen) had been stuck at 1.4.4 since 5 July, on every release since. It is the number you check to tell whether a phone has picked up an update, so it was answering the one question it exists to answer with July's answer. It now matches the version actually shipped, and the test suite fails if the two ever drift again.

= 1.3.0 =
* New tenants now show by name even when the plot's tenancy record hasn't been re-synced (the app resolves the current holder from the active assignment), and are flagged "New — exempt": you can still record what you see, but the system saves it exempt and sends no notice (they're inside the 1 March grace period).
* Vacant plots are now shown but not inspectable — they appear greyed/dashed on the list and map so you can see they're known vacancies, but you can't open a finding that the server would reject.
* Saving a finding from the Map now returns you to the Map (at the same position) instead of dropping you back to the List.
* Photos no longer block you: saving a finding returns immediately and photos upload in the background. New "Upload photos on Wi-Fi only" setting (Sync queue) holds large photo uploads for Wi-Fi while findings still sync on mobile data; on phones that can detect Wi-Fi it resumes automatically, otherwise tap "Upload photos now" when you're back on Wi-Fi.

= 1.2.14 =
* When you change a finding's rating, an auto-generated summary now updates to match (e.g. editing Pass to Minor no longer leaves a 'Pass — no issues recorded.' note). Summaries you typed yourself are left untouched.

= 1.2.13 =
* Fix: opening a finding you can only view (someone else's, and you're not the chair) no longer breaks the screen.
* Faster: viewing a plot you can't edit skips an unnecessary database lookup.

= 1.2.12 =
* You can now edit a finding you already recorded (to fix a mistake). Open the plot, change the rating/notes/issues and tap Update finding. The chair can edit any inspector's finding. Every change is logged.

= 1.2.11 =
* The Sync queue now flags a photo that can't sync because its finding is gone ("not linked to a finding"), and Retry tells you instead of silently doing nothing. Delete it and re-take.

= 1.2.10 =
* Tap the status pill to open a Sync queue screen: see exactly what's waiting to sync, retry, or delete an item that's stuck (e.g. a finding the server keeps rejecting).

= 1.2.9 =
* Fix: findings recorded with only a rating (no typed notes) now save. Previously the server required a written summary and silently rejected rating-only findings — which is why they sat in the queue. The summary is filled in automatically from the rating and any ticked issues.

= 1.2.8 =
* The version number now shows in the header next to the title, so you can always tell which build a device is running.

= 1.2.7 =
* Tap the status pill for a plain-language status report — which build you're running, how many findings/photos are waiting, and the last sync error.
* The app page now tells browsers not to cache it, so devices reliably pick up the latest version instead of getting stuck on an old one.

= 1.2.6 =
* Sync problems are no longer silent: if findings can't reach the server the status pill turns red, and tapping it shows the reason (and retries). Previously a failed sync just sat as "queued" with no explanation.

= 1.2.5 =
* You can now remove a photo you just added (a ✕ button on each new photo) before saving.
* Fix: the Save button no longer covers the last "Issues observed" tickbox (tenancy breach) — there's now room to scroll it clear of the button.

= 1.2.4 =
* Fix: findings now sync even if the app was first opened on an old address — it posts to whatever address you're actually on, instead of a stale one baked in at install time. (Photo previews also needed a companion change to the main plugin's security policy.)

= 1.2.3 =
* Map: dial back the plot-label scaling — labels were ballooning and overlapping when zoomed in. They now grow gently and stay proportional to the plot.

= 1.2.2 =
* The app now applies updates in a single reload (previously a new version needed two reloads or a reopen to take effect).

= 1.2.1 =
* Internal: code-review hardening on the new save endpoint (exception-safe filter cleanup; removed an unused security token).

= 1.2.0 =
* Fix: recorded findings now actually save to the server (they were only ever queued). The app posts to its own save endpoint instead of the committee admin form's, which had a mismatched security token and required fields the phone doesn't capture. Field findings record the logged-in inspector.
* Map: remembers your position when you switch between a plot and the map (no longer jumps back to the whole-site view).
* Map: plot labels now scale with the map as you zoom in, instead of shrinking relative to the plot.

= 1.1.9 =
* Fix: committee roles (chair, secretary, manager, committee, IT admin) keep inspector access permanently. The capability is now injected into the role definitions via MemberManager's role filter, so it is no longer wiped when the membership roles are rebuilt on an update.

= 1.1.8 =
* Internal: code-review polish on the map — finite-rotation guard on labels, hoisted the HTML-escape table, and deferred building plot popups until opened. No user-facing change.

= 1.1.7 =
* Map: actually rotate the plot labels (the previous release's rotation was being suppressed by a Leaflet stylesheet rule).

= 1.1.6 =
* Map: plot labels are now rotated to run along each plot (matching the admin Map Editor), instead of horizontal labels that overlapped each other.

= 1.1.5 =
* Map: plot number and tenant name are now always shown as labels on each plot, instead of only on hover.

= 1.1.4 =
* Map: draw each plot as its real footprint — a rotated rectangle that scales with the satellite imagery as you zoom — instead of a fixed-size dot, using the width/height/rotation from the admin Map Editor. Coloured by inspection status.

= 1.1.3 =
* Map: fix "Map data not yet available" when zooming in. The map now honours the tile provider's max native zoom (Esri imagery stops at zoom 19), so zooming in upscales the deepest real imagery instead of requesting tiles that don't exist.

= 1.1.2 =
* Internal: share the status-badge mapping between the plot list and map (new js/components/badge.js) so they can't drift; cancel the map's deferred resize timer on teardown. No user-facing change.

= 1.1.1 =
* Grant the inspector capability to the committee roles (chair, secretary, manager, committee, IT admin), not just administrators. On existing installs these roles were created after the inspector was first activated, so the original sync only reached administrators — bumping the caps version re-runs it.

= 1.1.0 =
* Plot Map view: the round's plots are drawn on a Leaflet map, coloured by inspection status (not inspected / Pass / Cat 2 / Cat 3). Tap a plot to inspect it.
* Leaflet is now bundled locally and precached by the service worker, so the map works offline.

= 1.0.0 =
* Initial release.
