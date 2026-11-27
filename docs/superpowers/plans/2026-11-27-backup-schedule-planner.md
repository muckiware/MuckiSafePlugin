# Backup Schedule Planner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A 24-hour timeline in the administration of MuckiSafePlugin that shows all backup schedules of active repositories as runtime-wide blocks, lets daily ones be moved by drag and drop, lets every schedule be switched on/off, and warns about backups that would be skipped because of overlaps.

**Architecture:** A PHP plan endpoint (`GET/POST /api/_action/muckisafe/backup-plan`) computes ready-made blocks with facility's `ScheduleCalculator` and saves all changes atomically through facility's `ScheduleConfiguration`. A Vue admin module renders the timeline, keeps changes locally, computes conflicts client-side and saves in one request.

**Tech Stack:** PHP 8.3+, Shopware 6.6/6.7 core (DBAL `Connection`, Symfony routing attributes, XML DI), `muckiware/facility-plugin` ^0.11.3, Shopware administration (Vue 3 options API, Twig templates, SCSS), Node ≥ 22 `node --test` for the pure JS helpers, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-27-backup-schedule-planner-design.md`

## Global Constraints

- Namespace `MuckiSafePlugin\`; Muckiware file header (copy from `src/Core/ConfigPath.php`); MIT; `declare(strict_types=1)`.
- Follow MuckiFacilityPlugin conventions: **non-final** classes, `protected` constructor-promoted dependencies; services in `src/Resources/config/services.xml` (no attributes for DI); routes via attributes, imported by `src/Resources/config/routes.xml`.
- No changes to `muckiware/facility-plugin`. Use its services `MuckiFacilityPlugin\Schedule\ScheduleCalculator` and `MuckiFacilityPlugin\Schedule\ScheduleConfiguration` (both `final` — construct real instances in tests, as facility's `tests/Schedule/ScheduleControllerTest.php` does).
- Do not depend on Checkout/Storefront.
- All code, comments, snippets keys and commit messages in English; snippets in `de-DE` and `en-GB`.
- Values from the spec: buffer default **15** min (config `MuckiSafePlugin.config.scheduleBufferMinutes`); drag grid **15** min; runtime = **max of last 5** non-null `backup_duration`; fallback **30 min** (`1800` s) marked estimated; locked statuses **`queued`, `running`**; daily cron = 5 fields, minute and hour single integers, the other three `*`; macros (`@daily`) are not daily.
- ACL: GET needs `muwa_backup_repository:read`, POST needs `muwa_backup_repository:update`. UI: viewer role `muwa_backup_repository.viewer`, editing `muwa_backup_repository.editor`.
- Git: work on branch `feat/backup-schedule-planner` in the MuckiSafePlugin repository. The working tree already contains **unrelated uncommitted changes** (`.github/workflows/main.yml`, `CHANGELOG.md`, `CLAUDE.md`, `README.md`, `composer.json`, `tests/TestBootstrap.php`). Always `git add` explicit paths of files you created/changed; never `git add -A`/`.`; never commit those six files (Task 9 handles docs without committing).
- Commands (run from the Muckidrive project root `/Users/torstenfreyda/shopdev/muckidrive`):
  - Unit tests: `ddev exec vendor/bin/phpunit --configuration=custom/static-plugins/MuckiSafePlugin/phpunit.unit.xml`
  - All tests: `ddev exec vendor/bin/phpunit --configuration=custom/static-plugins/MuckiSafePlugin` (needs `TEST_DATABASE_URL`)
  - PHPStan: `ddev exec bash -c "PROJECT_ROOT=/var/www/html vendor/bin/phpstan analyse --no-progress -c custom/static-plugins/MuckiSafePlugin/phpstan.neon"`
  - JS helper tests: `node --test custom/static-plugins/MuckiSafePlugin/tests/administration/`
  - Admin build: `bin/build-administration.sh`

## Review Focus

1. **Cron that is invalid or no longer parseable** (stored before validation got stricter): the plan must still load; the item is shown locked with no blocks instead of a 500. → test `testInvalidCronYieldsLockedItemWithoutOccurrences` in Task 3.
2. **DST days** (e.g. 2026-03-29 / 2026-10-25 in Europe/Berlin): window is 23 h / 25 h; occurrences and `startTime` must use wall-clock time; a `startTime` that does not exist that day (02:30 on spring-forward) must still save a valid cron. → tests in Task 2 and Task 3.
3. **Schedule starts running between GET and POST**: the save must fail with 409 and write nothing. → `testLockedScheduleAbortsWholeSave` in Task 4.
4. **Very frequent crons** (`* * * * *`): occurrence loop must terminate and not produce thousands of blocks silently forever. → `testEveryMinuteCronIsCappedAt1440Occurrences` in Task 3.
5. **Two blocks starting at the same minute**: exactly one is reported as skipped, deterministically (name order). → test in Task 6.

---

### Task 1: Buffer setting

**Files:**
- Modify: `src/Core/ConfigPath.php`, `src/Services/SettingsInterface.php`, `src/Services/Settings.php`, `src/Resources/config/config.xml`
- Test: `tests/Services/SettingsTest.php`

**Interfaces:**
- Produces: `ConfigPath::CONFIG_PATH_SCHEDULE_BUFFER_MINUTES = 'MuckiSafePlugin.config.scheduleBufferMinutes'`; `SettingsInterface::getScheduleBufferMinutes(): int`; `Settings::DEFAULT_SCHEDULE_BUFFER_MINUTES = 15`.

- [ ] **Step 1: Write failing tests** in `SettingsTest`:
  - `testScheduleBufferReadsConfigKey`: `getInt` called with `ConfigPath::CONFIG_PATH_SCHEDULE_BUFFER_MINUTES->value` returning `20` → `getScheduleBufferMinutes() === 20`.
  - `testScheduleBufferFallsBackToDefaultWhenNotPositive`: `getInt` returns `0` → `15`; returns `-5` → `15`.
- [ ] **Step 2: Run unit tests** → FAIL (undefined method).
- [ ] **Step 3: Implement** the enum case, interface method, `Settings::getScheduleBufferMinutes()` (`SystemConfigService::getInt`, non-positive → default), and a `<input-field type="int">` named `scheduleBufferMinutes` in `config.xml` (label "Backup planner buffer (minutes)" / "Puffer Backup-Planung (Minuten)", `defaultValue` 15, `min` 0) in the existing card.
- [ ] **Step 4: Run unit tests** → PASS (incl. existing `testConfigPathUsesPluginNamespace`).
- [ ] **Step 5: Commit** `git add src/Core/ConfigPath.php src/Services/SettingsInterface.php src/Services/Settings.php src/Resources/config/config.xml tests/Services/SettingsTest.php && git commit -m "feat: add schedule buffer setting"`

### Task 2: Daily cron helper

**Files:**
- Create: `src/Schedule/DailyCronExpression.php`
- Test: `tests/Schedule/DailyCronExpressionTest.php` (add to `phpunit.unit.xml` as `<directory>tests/Schedule</directory>`)

**Interfaces:**
- Produces (all `public static`):
  - `parse(string $cron): ?array` → `['hour' => int, 'minute' => int]` or `null` when not daily.
  - `toDisplayStartTime(string $cron, string $date, \DateTimeZone $schedule, \DateTimeZone $display): string` → `HH:MM` in display tz on `$date` (Y-m-d); throws `\InvalidArgumentException` when not daily.
  - `fromDisplayStartTime(string $startTime, string $date, \DateTimeZone $display, \DateTimeZone $schedule): string` → `"{minute} {hour} * * *"` in the schedule's tz (no leading zeros).

- [ ] **Step 1: Write failing tests**
  - `testParseAcceptsDailyCron`: `'30 2 * * *'` → `['hour'=>2,'minute'=>30]`; `' 0  0 * * * '` (extra spaces) → `['hour'=>0,'minute'=>0]`.
  - `testParseRejectsNonDaily` (data provider): `'0 */6 * * *'`, `'0 2 * * 0'`, `'0 2 1 * *'`, `'0 2 * 1 *'`, `'0,30 2 * * *'`, `'0 2-4 * * *'`, `'@daily'`, `'60 2 * * *'`, `'0 24 * * *'`, `'0 2 * * * *'` → `null`.
  - `testFromDisplayStartTimeConvertsTimezone`: `('03:15','2026-11-27', Europe/Berlin, UTC)` → `'15 2 * * *'`; same tz → `'15 3 * * *'`.
  - `testFromDisplayStartTimeOnSpringForwardGap`: `('02:30','2026-03-29', Europe/Berlin, Europe/Berlin)` returns a cron accepted by `parse()` (PHP shifts it to 03:30 → `'30 3 * * *'`).
  - `testToDisplayStartTimeConvertsTimezone`: `('15 2 * * *','2026-11-27', UTC, Europe/Berlin)` → `'03:15'`; on `'2026-07-01'` → `'04:15'`.
- [ ] **Step 2: Run unit tests** → FAIL.
- [ ] **Step 3: Implement** `DailyCronExpression` (split on whitespace; minute 0–59 and hour 0–23 must match `^\d{1,2}$`).
- [ ] **Step 4: Run unit tests** → PASS.
- [ ] **Step 5: Commit** `git add src/Schedule/DailyCronExpression.php tests/Schedule/DailyCronExpressionTest.php phpunit.unit.xml && git commit -m "feat: add daily cron expression helper"`

### Task 3: Schedule rows and plan builder

**Files:**
- Create: `src/Schedule/ScheduleRow.php`, `src/Schedule/ScheduleRowReader.php`, `src/Schedule/BackupPlanBuilder.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Schedule/BackupPlanBuilderTest.php` (unit), `tests/Schedule/ScheduleRowReaderTest.php` (integration, `IntegrationTestBehaviour`; not in `phpunit.unit.xml`)

**Interfaces:**
- Consumes: `DailyCronExpression` (Task 2); facility `ScheduleCalculator::next(string $expression, string $timezone, ?\DateTimeImmutable $from): \DateTimeImmutable` (UTC, strictly after `$from`, throws `\InvalidArgumentException`).
- Produces:
  - `ScheduleRow` with `public readonly` promoted props: `string $repositoryId` (hex), `string $name`, `bool $enabled`, `string $status`, `string $cronExpression`, `string $timezone`, `?int $maxDurationSeconds`, `int $durationSampleCount`.
  - `ScheduleRowReader(protected Connection $connection)`: `fetchActive(): array` (list<ScheduleRow>, ordered by name); `fetchForUpdate(string $repositoryId): ?ScheduleRow` (same row shape, `FOR UPDATE` on the schedule row; `null` if repository unknown, not active or without schedule).
  - `BackupPlanBuilder(protected ScheduleCalculator $calculator)`: `build(array $rows, string $date, \DateTimeZone $display, int $bufferMinutes): array`; constants `ESTIMATED_DURATION_SECONDS = 1800`, `MAX_OCCURRENCES = 1440`, `LOCKED_STATUSES = ['queued', 'running']`.
  - Payload shape (all times ISO 8601 UTC, `DATE_ATOM`):
    ```
    { date, timezone, windowStart, windowEnd, bufferMinutes,
      items: [ { repositoryId, name, enabled, status, editable, cronExpression, scheduleTimezone,
                 daily, startTime|null, durationSeconds, durationEstimated, durationSampleCount,
                 occurrences: [ {start, end} ] } ] }
    ```
    `windowStart`/`windowEnd` = `$date` 00:00 and next day 00:00 in `$display` (not added to spec's example, needed by the UI; 23 h/25 h on DST days).

Reader SQL notes: repositories `COALESCE(r.active, 0) = 1` joined to `muwa_backup_schedule`; durations via `ROW_NUMBER() OVER (PARTITION BY backup_repository_id ORDER BY created_at DESC)` on `muwa_backup_repository_stats WHERE backup_duration IS NOT NULL`, keep `rn <= 5`, `MAX()` and `COUNT()`.

Occurrence algorithm: `from = windowStart - duration`; repeat `start = calculator->next(cron, scheduleTz, from)`; stop when `start >= windowEnd` or `MAX_OCCURRENCES` reached; `end = start + duration`; `from = start`. Calculated for disabled schedules too. `InvalidArgumentException` → `occurrences = []`, `editable = false`, `daily = false`.

- [ ] **Step 1: Write failing builder tests** (fixed date `2026-11-27`, display `Europe/Berlin`, real `ScheduleCalculator`):
  - `testDailyScheduleHasOneOccurrenceAndStartTime`: row `'0 2 * * *'` Berlin, max 2700 s, 5 samples → `daily true`, `startTime '02:00'`, one occurrence `2026-11-27T01:00:00+00:00`–`01:45`, `durationEstimated false`, `durationSampleCount 5`, `windowStart '2026-11-26T23:00:00+00:00'`, `bufferMinutes` passed through.
  - `testRunCrossingMidnightFromPreviousDayIsIncluded`: `'30 23 * * *'`, 3600 s → two occurrences: starts `2026-11-26T22:30Z` and `2026-11-27T22:30Z`.
  - `testNonDailyScheduleListsAllRunsAndHasNoStartTime`: `'0 */6 * * *'`, 600 s → 4 occurrences, `daily false`, `startTime null`.
  - `testMissingDurationFallsBackToEstimate`: `maxDurationSeconds null` → `durationSeconds 1800`, `durationEstimated true`, `durationSampleCount 0`.
  - `testQueuedAndRunningAreNotEditable` (provider `queued`, `running` → false; `scheduled`, `failed`, `inactive` → true).
  - `testDisabledScheduleStillHasOccurrences`.
  - `testInvalidCronYieldsLockedItemWithoutOccurrences`.
  - `testEveryMinuteCronIsCappedAt1440Occurrences`: `'* * * * *'`, 60 s → `count(occurrences) === 1440`.
  - `testSpringForwardDayWindowIs23Hours`: date `2026-03-29` → `windowEnd - windowStart === 23 h`.
- [ ] **Step 2: Write failing reader integration test** `testFetchActiveReturnsMaxOfLastFiveDurations`: insert one active repository with schedule and 6 stats rows (durations 100, 900, 200, 300, 400, 500 with ascending `created_at`; the oldest is 900) plus one inactive repository with schedule → one row, `maxDurationSeconds 500`, `durationSampleCount 5`; and `testFetchForUpdateReturnsNullForInactiveRepository`. Insert rows with DBAL, filling facility's required repository columns.
- [ ] **Step 3: Run tests** → FAIL.
- [ ] **Step 4: Implement** `ScheduleRow`, `ScheduleRowReader`, `BackupPlanBuilder`; register reader and builder in `services.xml` (reader gets `Doctrine\DBAL\Connection`, builder gets `MuckiFacilityPlugin\Schedule\ScheduleCalculator`).
- [ ] **Step 5: Run unit and all tests** → PASS.
- [ ] **Step 6: Commit** `git add src/Schedule/ScheduleRow.php src/Schedule/ScheduleRowReader.php src/Schedule/BackupPlanBuilder.php src/Resources/config/services.xml tests/Schedule/BackupPlanBuilderTest.php tests/Schedule/ScheduleRowReaderTest.php && git commit -m "feat: build backup plan from schedules"`

### Task 4: Plan writer

**Files:**
- Create: `src/Schedule/PlanChange.php`, `src/Schedule/BackupPlanWriter.php`, `src/Schedule/Exception/PlanValidationException.php`, `src/Schedule/Exception/ScheduleNotFoundException.php`, `src/Schedule/Exception/ScheduleLockedException.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Schedule/PlanChangeTest.php`, `tests/Schedule/BackupPlanWriterTest.php` (unit)

**Interfaces:**
- Consumes: `ScheduleRowReader::fetchForUpdate()`, `ScheduleRow`, `DailyCronExpression::parse()/fromDisplayStartTime()`, `BackupPlanBuilder::LOCKED_STATUSES`; facility `ScheduleConfiguration::configure(string $repositoryId, bool $enabled, string $cronExpression, string $timezone): void`.
- Produces:
  - `PlanChange` (`public readonly string $repositoryId, ?bool $enabled, ?string $startTime`); `PlanChange::fromArray(array $data): self` throws `PlanValidationException`; `PlanChange::listFromArray(mixed $changes): array` (non-empty list, max 100, no duplicate `repositoryId`).
  - `PlanValidationException extends \InvalidArgumentException`, `ScheduleNotFoundException extends \RuntimeException`, `ScheduleLockedException extends \RuntimeException`.
  - `BackupPlanWriter(protected Connection $connection, protected ScheduleRowReader $reader, protected ScheduleConfiguration $configuration)`: `apply(string $date, \DateTimeZone $display, array $changes): void` (list<PlanChange>), everything inside `$connection->transactional(...)`.

Rules per change: row = `fetchForUpdate` (null → `ScheduleNotFoundException`); status in locked → `ScheduleLockedException`; `startTime` on non-daily → `PlanValidationException`; cron = `startTime ? fromDisplayStartTime(...) : row cron`; enabled = `change->enabled ?? row->enabled`; then `configure(row repositoryId, enabled, cron, row timezone)`.

- [ ] **Step 1: Write failing `PlanChangeTest`**: valid `{repositoryId: <uuid hex>, enabled: true, startTime: '03:15'}`; rejects invalid uuid, `startTime` `'3:15'`, `'24:00'`, `'03:60'`, non-bool `enabled`, change with neither field, empty list, 101 changes, duplicate repository ids.
- [ ] **Step 2: Write failing `BackupPlanWriterTest`** (mock `Connection` whose `transactional` invokes the callback; mock `ScheduleRowReader`; real `ScheduleConfiguration` over a separate mock `Connection` whose `fetchOne` returns `1` and whose `executeStatement` calls are captured):
  - `testMovesDailyScheduleInItsOwnTimezone`: row `'0 2 * * *'` tz UTC, change `startTime '03:15'`, display Berlin, date `2026-11-27` → executed params contain `cronExpression '15 2 * * *'`, `timezone 'UTC'`.
  - `testToggleKeepsCronExpression`: row `'0 */6 * * *'`, change `enabled false` → `cronExpression '0 */6 * * *'`, `enabled 0`.
  - `testStartTimeOnNonDailyScheduleIsRejected` → `PlanValidationException`, `executeStatement` never called.
  - `testLockedScheduleAbortsWholeSave`: two changes, second row status `running` → `ScheduleLockedException` and the exception propagates out of `transactional` (so DBAL rolls back).
  - `testUnknownRepositoryThrowsNotFound`.
- [ ] **Step 3: Run unit tests** → FAIL.
- [ ] **Step 4: Implement** classes; register writer in `services.xml` (`Doctrine\DBAL\Connection`, reader, `MuckiFacilityPlugin\Schedule\ScheduleConfiguration`).
- [ ] **Step 5: Run unit tests** → PASS.
- [ ] **Step 6: Commit** (explicit paths of the files above) `-m "feat: save backup plan changes atomically"`

### Task 5: API controller

**Files:**
- Create: `src/Controller/BackupPlanController.php`, `src/Resources/config/routes.xml` (copy facility's `routes.xml`, only the `Controller/*Controller.php` import)
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Controller/BackupPlanControllerTest.php` (unit; add `<directory>tests/Controller</directory>` to `phpunit.unit.xml`)

**Interfaces:**
- Consumes: `ScheduleRowReader::fetchActive()`, `BackupPlanBuilder::build()`, `BackupPlanWriter::apply()`, `PlanChange::listFromArray()`, `SettingsInterface::getScheduleBufferMinutes()`, exceptions from Task 4.
- Produces: `BackupPlanController(protected ScheduleRowReader $reader, protected BackupPlanBuilder $builder, protected BackupPlanWriter $writer, protected SettingsInterface $settings) extends AbstractController`, `#[Route(defaults: ['_routeScope' => ['api']])]`:
  - `plan(Request $request): JsonResponse` — `GET /api/_action/muckisafe/backup-plan`, name `api.action.muckisafe.backup_plan.get`, `PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:read']`; query `date` (default today in `timezone`), `timezone` (default `UTC`).
  - `save(RequestDataBag $data): Response` — `POST` same path, name `api.action.muckisafe.backup_plan.save`, ACL `['muwa_backup_repository:update']`; body `{date, timezone, changes}`; 204.
  - Error mapping: invalid `date` (not strict `Y-m-d`) / `timezone` / `PlanValidationException` / `\InvalidArgumentException` → `BadRequestHttpException`; `ScheduleNotFoundException` → `NotFoundHttpException`; `ScheduleLockedException` → `ConflictHttpException`.
  - Service registration: `public="true"`, `<call method="setContainer">` like facility's controllers.

- [ ] **Step 1: Write failing tests**: `testPlanReturnsBuilderPayloadWithConfiguredBuffer` (settings returns 20 → `bufferMinutes 20`); `testPlanRejectsInvalidTimezone`; `testPlanRejectsInvalidDate` (`'2026-13-01'`, `'27.11.2026'`); `testSaveReturns204`; `testSaveMapsLockedTo409`; `testSaveMapsNotFoundTo404`; `testSaveMapsValidationTo400`; `testRoutesDeclareAcl` (read the `#[Route]` attributes via reflection and assert the ACL arrays above).
- [ ] **Step 2: Run unit tests** → FAIL.
- [ ] **Step 3: Implement** controller, `routes.xml`, service definition.
- [ ] **Step 4: Run unit tests and PHPStan** → PASS / no errors.
- [ ] **Step 5: Smoke test**: `ddev exec bin/console cache:clear && ddev exec bin/console debug:router | grep muckisafe` → both routes listed.
- [ ] **Step 6: Commit** `-m "feat: add backup plan api endpoints"`

### Task 6: Timeline JS helpers

**Files:**
- Create: `src/Resources/app/administration/src/module/muckisafe-backup-plan/helper/timeline-math.js`, `.../helper/conflicts.js`
- Test: `tests/administration/timeline-math.test.mjs`, `tests/administration/conflicts.test.mjs` (`node:test` + `node:assert/strict`, import helpers by relative path; plain ES modules without Shopware globals)

**Interfaces:**
- Produces (named exports; all minutes are relative to `windowStart`, may be negative or > `dayLength`):
  - `toMinutes(iso: string, windowStartIso: string): number`
  - `clipSegments(occurrences: {start:number,end:number}[], dayLength: number): {from:number,to:number}[]` — clipped to `[0, dayLength]`, empty ones dropped.
  - `snapMinute(minute: number, dayLength: number, grid = 15): number` — rounds to grid, clamps to `[0, dayLength - grid]`.
  - `dailyOccurrences(startMinute: number, durationMinutes: number, dayLength: number): {start,end}[]` — runs starting at `startMinute - dayLength` and `startMinute`, keeping those intersecting the window.
  - `formatWallTime(windowStartIso: string, minute: number, timeZone: string): string` — `HH:MM` via `Intl.DateTimeFormat` (`hourCycle: 'h23'`).
  - `findConflicts(items: {repositoryId,name,enabled,occurrences:{start,end}[]}[], bufferMinutes: number): {skippedId, otherId, zone:{from,to}}[]` — enabled items only; pair conflicts if `a.start < b.end + buffer && b.start < a.end + buffer`; `skippedId` = later start, tie → name later in `localeCompare` order; `zone` = `[max(starts), min(ends)]`, or when that is empty the gap `[min(ends), max(starts)]`; one entry per occurrence pair.

- [ ] **Step 1: Write failing tests**:
  - `snapMinute(127, 1440) === 120`, `snapMinute(1439, 1440) === 1425`, `snapMinute(-10, 1440) === 0`.
  - `dailyOccurrences(1410, 60, 1440)` → `[{start:-30,end:30},{start:1410,end:1470}]`; `clipSegments` of that → `[{from:0,to:30},{from:1410,to:1440}]`.
  - `formatWallTime('2026-11-26T23:00:00Z', 135, 'Europe/Berlin') === '02:15'`; spring-forward `('2026-03-28T23:00:00Z', 180, 'Europe/Berlin') === '04:00'`.
  - conflicts: overlap 120–165 vs 150–250 → one conflict, `skippedId` = the 150 one, zone `{from:150,to:165}`; buffer-only 120–165 vs 175–200 with buffer 15 → conflict, zone `{from:165,to:175}`; same with buffer 5 → none; disabled item never conflicts; equal starts → skipped is the alphabetically later name.
- [ ] **Step 2: Run** `node --test …/tests/administration/` → FAIL.
- [ ] **Step 3: Implement** both helpers.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `-m "feat: add timeline and conflict helpers"`

### Task 7: Admin module and timeline component

**Files:**
- Create: `src/Resources/app/administration/src/main.js` (imports `./module/muckisafe-backup-plan`)
- Create: `.../module/muckisafe-backup-plan/index.js`, `.../snippet/de-DE.json`, `.../snippet/en-GB.json`
- Create: `.../component/muckisafe-backup-plan-timeline/index.js`, `muckisafe-backup-plan-timeline.html.twig`, `muckisafe-backup-plan-timeline.scss`

**Interfaces:**
- Consumes: helpers from Task 6.
- Produces:
  - Module `muckisafe-backup-plan` (`type: 'plugin'`), route `index` → component `muckisafe-backup-plan-index` (Task 8), privilege `muwa_backup_repository.viewer`, placed like facility's `muwa-backup-repository` module (same `parentPath`/settings placement).
  - Component `muckisafe-backup-plan-timeline`, props: `items` (view items: API item + `segments: {from,to}[]`, `startMinute: number|null`, `durationMinutes: number`, `dirty: boolean`), `conflicts` (from `findConflicts`), `dayLength: number`, `windowStart: string`, `timezone: string`, `canEdit: boolean`. Emits `move(repositoryId: string, startMinute: number)` and `toggle(repositoryId: string, enabled: boolean)`.
  - Snippet root key `muckisafe-backup-plan` (title, legend entries, `skippedWarning` "{name} will be skipped" / "{name} wird übersprungen", estimate tooltip "Estimated, no runtime measured yet" / "Geschätzt, noch keine Laufzeit gemessen", "max of last {count} runs" / "Maximum der letzten {count} Läufe", running badge, save/discard/confirm texts, day navigation).

Component behaviour (from spec): hour axis 0–24; "Load" row showing `conflicts[].zone` in red; one row per item with `sw-switch-field` (disabled when `!canEdit || !item.editable`; use facility's `isV6600` `v-model:value` vs `v-model` pattern), name, start time and duration label; block styles draggable / locked (hatched, non-daily or not editable) / disabled (dashed grey) / estimated (dashed outline, "~") / conflict (red outline, tooltip with skipped text); blocks crossing midnight rendered as their clipped segments. Drag with pointer events (`setPointerCapture`), live position = `snapMinute(...)`, emits `move` on pointer up; tooltip with `formatWallTime` while dragging; focused draggable block responds to ArrowLeft/ArrowRight with ±15 min. No drag when `!canEdit`, not daily or not editable. Legend below.

- [ ] **Step 1: Implement** module registration, snippets and component.
- [ ] **Step 2: Build** `bin/build-administration.sh` → completes without errors; `npm run eslint` in `custom/muckiware/master/Resources/app/administration` shows no errors for the new files (if the lint config covers plugin paths; otherwise skip and note it).
- [ ] **Step 3: Commit** `-m "feat: add backup plan timeline component"`

### Task 8: Planner page

**Files:**
- Create: `.../module/muckisafe-backup-plan/page/muckisafe-backup-plan-index/index.js`, `muckisafe-backup-plan-index.html.twig`, `muckisafe-backup-plan-index.scss`

**Interfaces:**
- Consumes: API from Task 5 via `Shopware.Application.getContainer('init').httpClient` with the auth header pattern of facility's detail page (`getApiHeader()`); timeline component and helpers.
- Produces: page component `muckisafe-backup-plan-index`.

Behaviour:
- Timezone `Intl.DateTimeFormat().resolvedOptions().timeZone`; date = today in that tz (`YYYY-MM-DD`); load `GET` with both; build view items (`toMinutes` for occurrences, `dayLength = (windowEnd - windowStart)/60000`, `startMinute` from first occurrence starting inside the window for daily items).
- `move` → set `startMinute`, recompute occurrences with `dailyOccurrences`, mark dirty, store change `{repositoryId, startTime: formatWallTime(...)}`; `toggle` → update `enabled`, store change `{repositoryId, enabled}` (merge per repository). Conflicts recomputed (`computed`) from current items.
- `canEdit` = `acl.can('muwa_backup_repository.editor')`.
- Smart-bar buttons Discard (reload) and Save (disabled when no changes). Save with conflicts → `sw-modal` listing every `skippedWarning`, requires clicking an explicit "Save anyway"; then `POST {date, timezone, changes}`; on success notification + reload; on 409 error notification "a backup is currently running" and reload; other errors → error notification, keep local changes.
- Previous/next day buttons; with unsaved changes a modal "Save or discard?" first (save → then navigate; discard → navigate). Same for `beforeRouteLeave` (confirm discard).

- [ ] **Step 1: Implement** the page.
- [ ] **Step 2: Build** `bin/build-administration.sh` → OK.
- [ ] **Step 3: Browser verification** on `https://muckidirve.ddev.site/admin` with at least: one daily repo, one `0 */6 * * *` repo, one disabled schedule, one repo without stats. Check: blocks and widths; drag snaps to 15 min and tooltip shows time; moving one block onto another shows red load zone and "will be skipped"; Save shows confirmation and the repository detail page then shows the new cron; toggling a locked block works and keeps its cron; day navigation with unsaved changes prompts; viewer-only user sees read-only timeline.
- [ ] **Step 4: Commit** `-m "feat: add backup plan page"`

### Task 9: Documentation

**Files:**
- Modify: `README.md` (section "Backup planner": where to find it, buffer setting, runtime rule, skip semantics), `CHANGELOG.md` (unreleased entry), `CLAUDE.md` (structure: `Schedule/`, `Controller/`, admin module, `tests/administration` + node test command)

- [ ] **Step 1: Edit** the three files.
- [ ] **Step 2: Do not commit.** These files already carry the user's uncommitted changes; report to the user that the docs edits are in the working tree and let them decide how to commit (and whether to bump `composer.json` version).

---

## Self-review notes

- Spec coverage: data scope (T3 reader), buffer config (T1), daily detection (T2), runtime max-of-5 + fallback (T3), occurrences incl. midnight (T3), lock running/queued (T3 editable, T4 409), atomic save (T4), API + ACL (T5), layout/styles/drag/keyboard/grid (T7), conflict wording/explicit confirmation/day navigation/leave guard (T6, T8), snippets (T7), tests (T1–T6), docs (T9).
- Addition beyond spec example: `windowStart`/`windowEnd` in the GET payload (needed for DST-correct rendering).
