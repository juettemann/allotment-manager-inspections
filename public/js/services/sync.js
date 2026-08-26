/**
 * Sync queue: drains pending_findings and pending_photos when online.
 *
 * Flow:
 *   1. For each pending finding: POST to am_inspection_record_finding.
 *      On success: store returned finding_id, reassign queued photos to it,
 *      delete the pending_finding record.
 *   2. For each pending photo with findingId set: upload via
 *      am_inspection_upload_photo, then delete the queued row.
 *
 * Triggered on:
 *   - App boot, if navigator.onLine
 *   - window 'online' event
 *   - Manual call after the user submits a finding while online
 *
 * Concurrency: a single in-flight drain at a time, protected by a promise lock.
 */

import * as store from './store.js';
import * as api from './api.js';
import * as net from './net.js';

let inFlight = null;

// Last sync error message (surfaced in the header pill so a silent failure —
// the reason findings "stay queued forever" — is visible + reportable).
let lastError = null;
export function getLastError() { return lastError; }

// Build tag, baked into this module — NOT read from window.amiData — so the
// on-screen diagnostic proves at a glance whether the device is running the
// latest code (vs a stale HTTP-cached copy). Sourcing it from the server would
// defeat it: the shell is fetched network-first, so it would report the fresh
// version even while the device ran a cached module.
//
// Being hand-maintained, it drifts, and a drifted build tag is worse than none:
// it stayed at 1.4.4 from 5 July while AMI_VERSION went to 1.5.0, so every
// release after that told the inspector it was running July's code and no one
// could tell a stale phone from a current one. Test_Build_Tag now fails the
// suite when this and AMI_VERSION disagree — bump BOTH or neither.
export const BUILD = '1.9.0';

/**
 * Full queue snapshot for the on-screen diagnostic (tap the status pill).
 * Reveals build, where writes POST, and — crucially — whether queued photos
 * are orphaned (no parent finding to attach to) vs findings that should sync.
 */
export async function diagnostics() {
	const findings = await store.allPendingFindings();
	const photos = await store.allPendingPhotos();
	return {
		build: BUILD,
		findings: findings.map((f) => ({ plot: f.plotId, round: f.roundId, rating: f.rating })),
		photos: photos.map((p) => ({ fid: p.findingId || null, pfid: p.pendingFindingId || null, file: p.filename })),
		lastError,
	};
}

const listeners = new Set();

export function onSyncChange(cb) {
	listeners.add(cb);
	return () => listeners.delete(cb);
}

function emit(state) {
	listeners.forEach((cb) => {
		try { cb(state); } catch (e) { /* swallow */ }
	});
}

export async function snapshot() {
	const findings = await store.allPendingFindings();
	const photos = await store.allPendingPhotos();
	return { findings: findings.length, photos: photos.length };
}

export async function syncOnce({ forcePhotos = false } = {}) {
	if (!navigator.onLine) return { drained: 0, remaining: await snapshot() };
	if (inFlight) return inFlight;
	inFlight = (async () => {
		lastError = null;
		emit({ status: 'syncing', ...(await snapshot()) });
		let drained = 0;

		const findings = await store.allPendingFindings();
		// Findings the server PERMANENTLY rejected (HTTP 400 — e.g. the plot is
		// the inspector's own, a duplicate, or vacant). Retrying never helps, so
		// they must NOT wedge the good findings behind them in the queue. (The old
		// code `break`-ed on ANY error, so one such record froze the whole rest of
		// the queue — the Vinery-round bug where 4 self-inspection findings held 4
		// valid ones hostage.) Skip past them, annotate the row, and drain the rest.
		let rejectedCount = 0;
		let firstRejection = null;
		for (const f of findings) {
			try {
				const result = await api.saveFinding({
					roundId:  f.roundId,
					plotId:   f.plotId,
					memberId: f.memberId,
					rating:   f.rating,
					notes:    f.notes,
					// Issue tickboxes (DB 2.11.2). Older queued rows
					// from before tickbox support don't carry the key
					// — saveFinding handles `issues === undefined` by
					// omitting the tickbox fields entirely (server
					// records NULL = "not assessed").
					issues:   f.issues,
					// Manual exemption / internal-review hold + its note
					// (undefined on older/graded queued rows → saveFinding
					// falls through to the rating path).
					exemption:      f.exemption,
					committeeNotes: f.committeeNotes,
				});
				const newFindingId = result && (result.finding_id || result.id);
				if (newFindingId) {
					await store.reassignPhotosToFinding(f.id, newFindingId);
				}
				await store.deletePendingFinding(f.id);
				drained++;
			} catch (e) {
				// HTTP 400 = a per-record validation rejection (self-inspection,
				// duplicate, vacant plot…). It will never succeed as-is, so record
				// WHY on the row (the queue view surfaces it) and move on to the
				// next finding rather than freezing the queue.
				if (e && e.code === 400) {
					rejectedCount++;
					const why = (e && e.message) ? e.message : 'Rejected by the server';
					if (!firstRejection) firstRejection = why;
					try { await store.markFindingRejected(f.id, why); } catch (_) { /* non-fatal */ }
					console.warn('Sync: finding permanently rejected, skipping', e);
					continue;
				}
				// 403 (token/permission), 5xx, or the server being unreachable is a
				// whole-queue condition — stop and retry the batch later rather than
				// hammer every remaining record now. Photos for the un-synced
				// findings stay queued tagged with pendingFindingId.
				lastError = (e && e.message) ? e.message : 'Sync failed';
				console.warn('Sync: finding failed — will retry the batch later', e);
				break;
			}
		}

		const photos = await store.allPendingPhotos();
		const pendingFindingIds = new Set((await store.allPendingFindings()).map((f) => f.id));
		// Photos are large — only spend mobile data on them when policy allows
		// (Wi-Fi-only off, or on-and-on-Wi-Fi, or the user forced an upload).
		// Findings (tiny) already drained above regardless of connection.
		const allowPhotos = net.photosAllowedNow({ force: forcePhotos });
		let orphanedPhotos = 0;
		let heldPhotos = 0;
		for (const p of photos) {
			if (!p.findingId) {
				// No real finding id yet. If its pending parent is still queued
				// it's legitimately waiting; otherwise the parent is gone and
				// this photo can NEVER sync — count it as orphaned so we can
				// surface it instead of silently skipping it forever.
				if (!(p.pendingFindingId && pendingFindingIds.has(p.pendingFindingId))) {
					orphanedPhotos++;
				}
				continue;
			}
			if (!allowPhotos) {
				// Wi-Fi-only is on and we're not (known to be) on Wi-Fi — hold the
				// photo rather than burn mobile data. Surfaced as a non-error
				// "waiting for Wi-Fi" state, not a failure.
				heldPhotos++;
				continue;
			}
			try {
				await api.uploadPhoto({ findingId: p.findingId, blob: p.blob, filename: p.filename, caption: p.caption });
				await store.deletePendingPhoto(p.id);
				drained++;
			} catch (e) {
				lastError = (e && e.message) ? e.message : 'Photo upload failed';
				console.warn('Sync: photo failed', e);
				break;
			}
		}

		// Permanently-rejected findings need a human — most often because they're
		// the inspector's OWN plots (self-inspection is blocked), so another
		// committee member has to record them. Surface that distinctly from a
		// transient wedge; the row keeps its data so nothing is lost.
		if (!lastError && rejectedCount > 0) {
			lastError = rejectedCount + ' finding(s) can’t be saved from this device: '
				+ firstRejection + ' Open the queue to see which plots.';
		}

		// Orphaned photos would otherwise sit "waiting" forever with no error.
		if (!lastError && orphanedPhotos > 0) {
			lastError = orphanedPhotos + ' photo(s) aren’t linked to a finding and can’t sync — open the queue and delete them, then re-take the photo on the plot.';
		}

		const remaining = await snapshot();
		const stillQueued = (remaining.findings || 0) + (remaining.photos || 0);
		let status;
		let message = lastError;
		if (lastError && stillQueued > 0) {
			status = 'error';
		} else if (heldPhotos > 0) {
			// Not a failure — photos are deliberately waiting for Wi-Fi.
			status = 'held';
			message = heldPhotos + ' photo(s) waiting for Wi-Fi.';
		} else {
			status = navigator.onLine ? 'online' : 'offline';
		}
		emit({ status, message, held: heldPhotos, ...remaining });
		return { drained, remaining, error: lastError, held: heldPhotos };
	})().finally(() => { inFlight = null; });
	return inFlight;
}

export function startAutoSync() {
	// Emit initial state.
	(async () => {
		emit({ status: navigator.onLine ? 'online' : 'offline', ...(await snapshot()) });
	})();

	window.addEventListener('online', () => { syncOnce(); });
	window.addEventListener('offline', async () => {
		emit({ status: 'offline', ...(await snapshot()) });
	});

	// Auto-resume held photos when the connection switches to Wi-Fi (Android
	// Chrome fires connection 'change'; iOS has no such event, so there the
	// inspector taps "Upload photos now" from the queue). Cheap when nothing
	// is held — syncOnce no-ops if the queue is empty.
	net.onConnectionChange(() => { if (navigator.onLine) syncOnce(); });

	// Try once at boot.
	if (navigator.onLine) syncOnce();

	// Gentle poll every 60 s as a backstop.
	setInterval(() => { if (navigator.onLine) syncOnce(); }, 60_000);
}
