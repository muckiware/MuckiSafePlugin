/**
 * Pure timeline math for the backup schedule planner. All minutes are relative to the window start
 * and may be negative or larger than the day length (occurrences are delivered unclipped).
 * No Shopware globals, so Node tests and the admin build can both import this module.
 */

const MS_PER_MINUTE = 60000;

/**
 * @param {string} iso ISO timestamp
 * @param {string} windowStartIso ISO timestamp of the window start
 * @returns {number} minutes since the window start
 */
export function toMinutes(iso, windowStartIso) {
    return (Date.parse(iso) - Date.parse(windowStartIso)) / MS_PER_MINUTE;
}

/**
 * Clips occurrences to [0, dayLength] and drops empty ones.
 *
 * @param {{start: number, end: number}[]} occurrences
 * @param {number} dayLength
 * @returns {{from: number, to: number}[]}
 */
export function clipSegments(occurrences, dayLength) {
    return occurrences
        .map(({ start, end }) => ({ from: Math.max(start, 0), to: Math.min(end, dayLength) }))
        .filter(({ from, to }) => from < to);
}

/**
 * Rounds to the grid and clamps to [0, dayLength - grid].
 *
 * @param {number} minute
 * @param {number} dayLength
 * @param {number} [grid]
 * @returns {number}
 */
export function snapMinute(minute, dayLength, grid = 15) {
    const snapped = Math.round(minute / grid) * grid;
    return Math.min(Math.max(snapped, 0), dayLength - grid);
}

/**
 * Runs of a daily item that intersect the window: the one started the previous day and the one
 * started today. On a DST day the window is shorter or longer than 24 h, hence the dayLength.
 *
 * @param {number} startMinute
 * @param {number} durationMinutes
 * @param {number} dayLength
 * @returns {{start: number, end: number}[]}
 */
export function dailyOccurrences(startMinute, durationMinutes, dayLength) {
    return [startMinute - dayLength, startMinute]
        .map((start) => ({ start, end: start + durationMinutes }))
        .filter(({ start, end }) => end > 0 && start < dayLength);
}

/**
 * Formats the wall clock time (HH:MM) of a window minute in the given time zone.
 *
 * @param {string} windowStartIso
 * @param {number} minute
 * @param {string} timeZone IANA time zone
 * @returns {string}
 */
export function formatWallTime(windowStartIso, minute, timeZone) {
    const date = new Date(Date.parse(windowStartIso) + minute * MS_PER_MINUTE);
    return new Intl.DateTimeFormat('en-GB', {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(date);
}
