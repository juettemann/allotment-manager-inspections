/**
 * The route table.
 *
 * Run with `node --test "tests/js/*.test.mjs"` — Node's built-in runner. The
 * router imports nothing and touches the DOM only inside start()/parseRoute(),
 * so matchRoute() imports and runs as-is.
 *
 * These exist because a route is the only thing standing between a screen and
 * being unreachable, and losing one is invisible: every module behind it still
 * loads, and every test of those modules still passes. The website had exactly
 * that failure (ams#886) — a live endpoint with nothing left that could reach
 * it — and the field app's re-inspection screen is reached by route alone.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { matchRoute } from '../../public/js/router.js';

test('the plot editor route carries the round and plot', () => {
	const r = matchRoute('#/round/12/plot/34');

	assert.equal(r.name, 'findingEditor');
	assert.equal(r.params.roundId, '12');
	assert.equal(r.params.plotId, '34');
	assert.equal(r.params.mode, undefined, 'a plain plot URL is not a follow-up');
});

test('the re-inspection has a route of its own', () => {
	const r = matchRoute('#/round/12/plot/34/follow-up');

	assert.equal(r.name, 'findingEditor', 'same editor, same plot — a mode, not a screen');
	assert.equal(r.params.roundId, '12');
	assert.equal(r.params.plotId, '34');
	assert.equal(r.params.mode, 'follow-up');
});

test('a trailing slash does not change which route matches', () => {
	assert.equal(matchRoute('#/round/1/plot/2/').params.mode, undefined);
	assert.equal(matchRoute('#/round/1/plot/2/follow-up/').params.mode, 'follow-up');
});

test('an unknown suffix is not silently treated as the editor', () => {
	// It must not fall through to the plot editor and quietly record against
	// the wrong visit — better a Not-found the inspector can see.
	assert.equal(matchRoute('#/round/1/plot/2/followup').name, 'notFound');
	assert.equal(matchRoute('#/round/1/plot/2/anything').name, 'notFound');
});

test('the rounds and plot-list routes still resolve', () => {
	assert.equal(matchRoute('#/').name, 'roundPicker');
	assert.equal(matchRoute('#/round/9').name, 'plotPicker');
	assert.equal(matchRoute('#/round/9').params.roundId, '9');
	assert.equal(matchRoute('#/queue').name, 'queue');
});
