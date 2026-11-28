import test from 'node:test';
import assert from 'node:assert/strict';
import { findConflicts } from '../../src/Resources/app/administration/src/module/muckisafe-backup-plan/helper/conflicts.js';

const item = (repositoryId, name, start, end, enabled = true) => ({
    repositoryId,
    name,
    enabled,
    occurrences: [{ start, end }],
});

test('overlapping items conflict and the later start is skipped', () => {
    const conflicts = findConflicts([item('a', 'A', 120, 165), item('b', 'B', 150, 250)], 0);
    assert.deepEqual(conflicts, [{ skippedId: 'b', otherId: 'a', zone: { from: 150, to: 165 } }]);
});

test('buffer-only conflict reports the gap as zone', () => {
    const items = [item('a', 'A', 120, 165), item('b', 'B', 175, 200)];
    assert.deepEqual(findConflicts(items, 15), [{ skippedId: 'b', otherId: 'a', zone: { from: 165, to: 175 } }]);
    assert.deepEqual(findConflicts(items, 5), []);
});

test('disabled items never conflict', () => {
    assert.deepEqual(findConflicts([item('a', 'A', 120, 165), item('b', 'B', 130, 170, false)], 15), []);
});

test('equal starts skip the alphabetically later name', () => {
    const conflicts = findConflicts([item('z', 'Zeta', 100, 160), item('a', 'Alpha', 100, 160)], 0);
    assert.equal(conflicts.length, 1);
    assert.equal(conflicts[0].skippedId, 'z');
    assert.equal(conflicts[0].otherId, 'a');
});

test('one entry per occurrence pair', () => {
    const a = { repositoryId: 'a', name: 'A', enabled: true, occurrences: [{ start: -30, end: 30 }, { start: 1410, end: 1470 }] };
    const b = { repositoryId: 'b', name: 'B', enabled: true, occurrences: [{ start: 0, end: 60 }, { start: 1420, end: 1480 }] };
    assert.equal(findConflicts([a, b], 0).length, 2);
});
