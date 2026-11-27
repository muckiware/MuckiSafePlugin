# Backup schedule planner — design

Date: 2026-11-27
Plugin: `muckiware/muckisafe-plugin` (depends on `muckiware/facility-plugin` `^0.11.3`)

## Intent

MuckiSafe instances run several restic backups (one per `muwa_backup_repository`), each with its own
cron schedule (`muwa_backup_schedule`). Today every schedule is edited in isolation on the repository
detail page, so nobody sees whether backups overlap. The goal is that **backups do not overlap in time
and the load can be distributed across the day in a clear way**.

The planner is a 24-hour timeline in the administration that shows every backup as a block whose width
is its runtime. Daily backups can be moved by drag and drop; every backup can be switched on or off.

## Goals

- One screen showing all schedules of active repositories on a 24-hour timeline.
- Block width = runtime of the backup.
- Drag and drop changes the start time of daily schedules.
- Switch schedules on/off from the same screen.
- Overlaps (including a safety buffer) are clearly visible.
- All changes are saved together and atomically.

## Non-goals

- Editing non-daily cron expressions (weekly, every 6 h, …) — they stay in the repository detail page.
- Automatic scheduling / "find a free slot" suggestions.
- Changing the repository `active` flag.
- Preventing overlaps — the planner warns only.
- Any change to `muckiware/facility-plugin`.

## Anti-goals (failure even if it technically works)

- A hand-written cron expression is silently replaced by a daily one.
- Saving from the planner breaks a backup that is currently queued or running.
- A save that fails halfway leaves some schedules changed and others not.

## Current state (facility plugin, v0.11.3)

- `muwa_backup_schedule` (`BackupScheduleDefinition`): one row per repository (`backup_repository_id`
  unique, upsert), fields `enabled`, `cron_expression`, `timezone`, `status`
  (`inactive|scheduled|queued|running|…`), `last_execution_time`, `next_execution_time`, `last_error`,
  `run_token`. All fields are `WriteProtected(SYSTEM_SCOPE)`; writes go through
  `MuckiFacilityPlugin\Schedule\ScheduleConfiguration::configure($repositoryId, $enabled, $cron, $timezone)`.
- `configure()` validates via `ScheduleCalculator::next()`, upserts the row, resets `run_token` to `NULL`,
  `last_error` to `NULL` and sets `status` to `scheduled`/`inactive`. **Calling it on a queued/running
  schedule detaches the running job from its run token** — hence the lock rule below.
- `ScheduleCalculator::next()` / `nextRuns()` compute run dates with `dragonmantank/cron-expression`,
  returned in UTC.
- Both are registered as (private) services in facility's `services.xml` and can be injected.
- Existing API: `PUT /api/_action/muwa/backup/{id}/schedule` (ACL `muwa_backup_repository:update`),
  `POST /api/_action/muwa/backup/schedule/preview` (ACL `muwa_backup_repository:read`).
- Runtime: there is no configured duration. `muwa_backup_repository_stats.backup_duration` (seconds,
  nullable) holds the measured runtime of a backup run. `BackupRepositoryDefinition` has `active` and a
  `schedule` association plus a stats association.
- MuckiSafePlugin currently has no administration code and no controllers.

## Approaches considered

1. **Own planning endpoint in MuckiSafe, UI only renders (chosen).** `GET` returns ready-made blocks
   computed with facility's `ScheduleCalculator`; `POST` saves all changes in one DB transaction via
   `ScheduleConfiguration`. Cron semantics identical to the real scheduler, planning logic testable with
   PHPUnit, atomic save. Cost: more PHP, MuckiSafe couples to facility's `Schedule` classes (acceptable:
   it already hard-depends on facility).
2. **Frontend only.** Admin reads repositories/schedules/stats via the DAL API, calls facility's preview
   per locked schedule and `PUT`s each changed schedule. No PHP, but many requests, non-atomic saves and
   cron "is daily" logic duplicated in JS.
3. **Build it into the facility plugin.** Rejected: the feature is wanted in MuckiSafe.

The user picked approach 1.

## Decisions made with the user

- **Layout:** Gantt style — one row per backup, toggle switch and label (name, start, runtime) on the left,
  timeline on the right, a **"Load" row** on top that marks overlap zones in red. (Mockup "A" from the
  brainstorming session.)
- **Daily vs. other crons:** schedules of the form `M H * * *` are draggable blocks. All other schedules are
  shown with all their occurrences in the displayed day as **locked** (hatched) blocks; they count for
  overlap detection and keep being edited in the repository detail page.
- **Toggle** works for every schedule (daily and locked).
- **Overlap handling:** warn only, never prevent. Two blocks conflict if they are closer than a
  **15-minute buffer** (`a.start < b.end + buffer && b.start < a.end + buffer`).
- **Architecture:** approach 1.
- **Consequence of overlaps made explicit:** facility runs only one backup at a time (global lock
  `mucki-facility.backup`); a backup that starts while another is running is marked `skipped` with
  `BackupAlreadyRunningException` and is **not** retried. The planner therefore words conflicts as
  "Backup X will be skipped" (the later-starting one of a pair), not just "overlaps", and the save
  confirmation must be explicitly confirmed when conflicts exist. Still no hard prevention.
- **Runtime used for block width:** **maximum `backup_duration` of the last 5 stats rows** with a non-null
  `backup_duration` (ordered by `created_at` desc). `backup_duration` is stored for failed runs too and
  there is no success flag, so the maximum keeps a single aborted run from shrinking the block. No rows →
  30-minute estimate.

## Design

### Data scope

- All repositories with `active = true` that have a `muwa_backup_schedule` row, enabled or not
  (disabled ones must be visible to switch them on).
- Repositories without a schedule row are not shown.

### Plugin configuration

- New config key `MuckiSafePlugin.config.scheduleBufferMinutes` (int, default `15`), new case in
  `Core\ConfigPath`, field in `config.xml`, getter in `SettingsInterface`/`Settings`.

### Backend (MuckiSafePlugin)

**Service `MuckiSafePlugin\Schedule\BackupPlanProvider`** (name is the builder's call) — builds the plan:

- Loads repositories + schedules + the last 5 stats rows with non-null `backup_duration` per repository
  (by `created_at` desc) and takes their maximum.
- `daily` detection: cron has exactly 5 fields, minute and hour are single integers, day-of-month, month and
  day-of-week are `*`. Macros like `@daily` count as non-daily (locked) to avoid rewriting them.
- Duration: maximum of the last 5 measured `backup_duration` values; if none, **30 minutes** with
  `durationEstimated = true`. Also return `durationSampleCount` (0–5) so the UI tooltip can say
  "max of last N runs".
- Occurrences: all runs whose interval `[start, start + duration)` intersects the displayed day window
  (`date` 00:00–24:00 in the display timezone). Use `ScheduleCalculator::next()` starting from
  `windowStart - duration` and iterate until `start >= windowEnd`. Occurrences are calculated for disabled
  schedules too (rendered greyed out).
- `editable = false` when `status` is `queued` or `running` (no drag, no toggle, badge "running").

**Service `MuckiSafePlugin\Schedule\BackupPlanWriter`** — applies changes:

- Wraps all changes in `Connection::transactional()`; uses facility's `ScheduleConfiguration::configure()`
  (same DBAL connection, so the transaction covers it).
- For each change: repository must exist, be active and have a schedule; schedule must not be
  `queued`/`running` (re-checked inside the transaction with `SELECT … FOR UPDATE`) → otherwise the whole
  save fails with a 4xx.
- `startTime` is only accepted for daily schedules. It is given as `HH:MM` in the display timezone for the
  displayed `date`; it is converted to the **schedule's own timezone** and written as `M H * * *`
  (timezone unchanged).
- `enabled` without `startTime` keeps the cron unchanged.
- Only changed schedules are sent by the UI and written (avoids resetting `run_token`/`last_error` of
  untouched schedules).

**Controller `MuckiSafePlugin\Controller\BackupPlanController`** (`_routeScope: api`, XML-registered,
public, `setContainer` like facility's controllers):

- `GET /api/_action/muckisafe/backup-plan?date=YYYY-MM-DD&timezone=Europe/Berlin`
  ACL `muwa_backup_repository:read`. Defaults: today, `UTC`. Invalid timezone/date → 400.

  ```json
  {
    "date": "2026-11-27",
    "timezone": "Europe/Berlin",
    "bufferMinutes": 15,
    "items": [
      {
        "repositoryId": "…", "name": "Shop DB",
        "enabled": true, "status": "scheduled", "editable": true,
        "cronExpression": "0 2 * * *", "scheduleTimezone": "Europe/Berlin",
        "daily": true, "startTime": "02:00",
        "durationSeconds": 2700, "durationEstimated": false, "durationSampleCount": 5,
        "occurrences": [{ "start": "2026-11-27T01:00:00+00:00", "end": "2026-11-27T01:45:00+00:00" }]
      }
    ]
  }
  ```
  (`startTime` is in the display timezone and only set for daily schedules.)

- `POST /api/_action/muckisafe/backup-plan` ACL `muwa_backup_repository:update`.

  ```json
  { "date": "2026-11-27", "timezone": "Europe/Berlin",
    "changes": [ { "repositoryId": "…", "enabled": true, "startTime": "03:15" } ] }
  ```
  Returns 204; 400 for invalid input, 404 for unknown repository, 409 for queued/running schedules.
  On any error nothing is written.

### Administration (MuckiSafePlugin)

- New admin entry `src/Resources/app/administration/src/main.js` and module `muckisafe-backup-plan`,
  placed in the navigation next to facility's backup repository module. ACL: viewing needs
  `muwa_backup_repository:read`, dragging/toggling/saving needs `muwa_backup_repository:update`
  (read-only view otherwise).
- Supports Shopware 6.6 and 6.7 (follow facility's `isV6600`/`isV6500` `v-model` pattern).
- Layout as described above: header with date, previous/next day buttons (so weekly crons can be checked)
  and the display timezone (browser timezone); "Load" row; one row per backup sorted by start time.
- Block styles: draggable (solid colour), locked non-daily (hatched, not draggable), disabled (dashed
  outline, grey), estimated duration (dashed outline + "~"), conflict (red outline). Blocks crossing
  midnight are split into two segments. Legend below the timeline.
- Drag: native pointer events (no new library), snapping to a **15-minute grid**, clamped to 00:00–23:45.
  Time tooltip while dragging. Keyboard alternative: focused block moves ±15 min with arrow keys.
- Conflicts are computed client-side live while dragging (enabled blocks only; disabled ones never
  conflict), using `bufferMinutes` from the API. The pure overlap/conflict function lives in its own
  helper module.
- Conflict wording: each conflicting pair names the later-starting backup as "will be skipped"
  (tooltip on the block, entry in a conflict list under the timeline).
- Changes are kept locally (dirty state) until **Save**. If conflicts exist, Save shows a confirmation
  listing every backup that would be skipped; saving requires explicitly confirming that dialog.
  Discard button resets to server state. Leaving the page with unsaved changes asks for confirmation.
- Day navigation with unsaved changes asks "save or discard" first; changes are never carried over to
  another day (builder's call, avoids DST offsets when converting `startTime` for a different date).
- After save the plan is reloaded from the server.
- Snippets `de-DE` and `en-GB`.

### Tests

- PHPUnit unit tests (in `phpunit.unit.xml`): daily detection, occurrence calculation incl. midnight wrap
  and DST day, duration fallback, display-timezone → schedule-timezone conversion, conflict-free input
  validation.
- PHPUnit integration tests: controller ACL, GET shape, POST atomicity (one invalid change → nothing
  written), 409 for running schedule.
- JS unit test for the conflict helper if the build provides Jest for plugin admin code (builder checks).

## Calls made without the user (check while reading)

- Toggle switches the schedule's `enabled`, not the repository's `active`.
- 30-minute estimate when no runtime is known; shown as dashed block.
- 15-minute drag grid; display in browser timezone, schedule timezone preserved on save.
- Save button with batch save instead of saving on every drop.
- Buffer configurable in plugin config (default 15).
- Queued/running schedules are locked (no drag, no toggle) because `configure()` resets the run token.
- `@daily`-style macros are treated as non-daily (locked).
- Previous/next day navigation (to inspect weekly crons); no carry-over of unsaved changes.

## Left to the builder

- Class/file names, exact admin navigation parent and icon, colour palette.
- How the admin build picks up MuckiSafe's new admin entry in Muckidrive (Vite build of
  `muckiware/master`) — follow how facility's admin code is built.
- Version bump (`composer.json`, `CHANGELOG.md`) and README section.
