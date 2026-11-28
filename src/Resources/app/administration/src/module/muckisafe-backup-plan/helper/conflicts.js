/**
 * Overlap and buffer conflict detection between planned backup runs. Pure ES module, minutes are
 * relative to the window start.
 */

/**
 * @typedef {{repositoryId: string, name: string, enabled: boolean, occurrences: {start: number, end: number}[]}} PlanItem
 */

/**
 * Finds conflicts between all enabled items: two occurrences conflict when they overlap or are
 * closer together than the buffer. The later start is the skipped run; on equal starts the item
 * whose name sorts later. One entry is returned per conflicting occurrence pair.
 *
 * @param {PlanItem[]} items
 * @param {number} bufferMinutes
 * @returns {{skippedId: string, otherId: string, zone: {from: number, to: number}}[]}
 */
export function findConflicts(items, bufferMinutes) {
    const enabled = items.filter((item) => item.enabled);
    const conflicts = [];

    for (let i = 0; i < enabled.length; i += 1) {
        for (let j = i + 1; j < enabled.length; j += 1) {
            conflicts.push(...pairConflicts(enabled[i], enabled[j], bufferMinutes));
        }
    }

    return conflicts;
}

function pairConflicts(first, second, bufferMinutes) {
    const conflicts = [];

    first.occurrences.forEach((a) => {
        second.occurrences.forEach((b) => {
            if (!(a.start < b.end + bufferMinutes && b.start < a.end + bufferMinutes)) {
                return;
            }
            const firstSkipped = isSkipped(first, a, second, b);
            conflicts.push({
                skippedId: firstSkipped ? first.repositoryId : second.repositoryId,
                otherId: firstSkipped ? second.repositoryId : first.repositoryId,
                zone: conflictZone(a, b),
            });
        });
    });

    return conflicts;
}

function isSkipped(first, a, second, b) {
    if (a.start !== b.start) {
        return a.start > b.start;
    }
    return first.name.localeCompare(second.name) > 0;
}

function conflictZone(a, b) {
    const from = Math.max(a.start, b.start);
    const to = Math.min(a.end, b.end);
    if (from < to) {
        return { from, to };
    }
    return { from: to, to: from };
}
