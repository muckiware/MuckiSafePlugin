import test from 'node:test';
import assert from 'node:assert/strict';
import {
    toMinutes,
    clipSegments,
    snapMinute,
    dailyOccurrences,
    formatWallTime,
} from '../../src/Resources/app/administration/src/module/muckisafe-backup-plan/helper/timeline-math.js';

test('toMinutes is relative to window start', () => {
    assert.equal(toMinutes('2026-11-26T23:30:00Z', '2026-11-26T23:00:00Z'), 30);
    assert.equal(toMinutes('2026-11-26T22:00:00Z', '2026-11-26T23:00:00Z'), -60);
});

test('snapMinute rounds to the grid and clamps', () => {
    assert.equal(snapMinute(127, 1440), 120);
    assert.equal(snapMinute(1439, 1440), 1425);
    assert.equal(snapMinute(-10, 1440), 0);
});

test('dailyOccurrences returns both runs touching the window', () => {
    const occurrences = dailyOccurrences(1410, 60, 1440);
    assert.deepEqual(occurrences, [{ start: -30, end: 30 }, { start: 1410, end: 1470 }]);
    assert.deepEqual(clipSegments(occurrences, 1440), [{ from: 0, to: 30 }, { from: 1410, to: 1440 }]);
});

test('dailyOccurrences keeps a single run when nothing wraps', () => {
    assert.deepEqual(dailyOccurrences(120, 45, 1440), [{ start: 120, end: 165 }]);
});

test('clipSegments drops occurrences outside the window', () => {
    assert.deepEqual(clipSegments([{ start: -50, end: -10 }, { start: 1500, end: 1560 }], 1440), []);
});

test('formatWallTime renders wall clock time in the time zone', () => {
    assert.equal(formatWallTime('2026-11-26T23:00:00Z', 135, 'Europe/Berlin'), '02:15');
    assert.equal(formatWallTime('2026-03-28T23:00:00Z', 180, 'Europe/Berlin'), '04:00');
});
