# MuckiSafePlugin — Claude context

Shopware 6 plugin built specifically for **MuckiSafe** instances, used together with
`muckiware/facility-plugin` (backup/restore via restic).

**Important:** This is a Muckiware plugin, not a LightsOn plugin — the namespace is
`MuckiSafePlugin\`, **not** `LightsOn\`. Follow the conventions of `MuckiFacilityPlugin`
(non-final classes, `protected` constructor-promoted dependencies, Muckiware file headers, MIT).

This directory is a Git checkout of the upstream repository
(`https://github.com/muckiware/MuckiSafePlugin.git`, branch `main`) under `custom/static-plugins/`.
Changes go as regular commits into this repository; bump `version` in `composer.json` and
`CHANGELOG.md` for releases.

## Facts

| | |
|---|---|
| Composer package | `muckiware/muckisafe-plugin`, version `v0.1.0` |
| Plugin class | `MuckiSafePlugin\MuckiSafePlugin` |
| Supported | Shopware `~6.6.10.14 \|\| ~6.7.6.0`, PHP `~8.3 \|\| ~8.4 \|\| ~8.5` |
| Config key prefix | `MuckiSafePlugin.config.` |
| Plugin dependency | `muckiware/facility-plugin` `^0.11.3` (`MuckiFacilityPlugin`) — must be installed and active first |

## Structure

```
src/
├── MuckiSafePlugin.php               # Plugin class, delegates lifecycle to Setup
├── Core/
│   └── ConfigPath.php                # Enum of all configuration keys
├── Controller/
│   └── BackupPlanController.php      # GET/POST /api/_action/muckisafe/backup-plan (ACL muwa_backup_repository:read/update)
├── Schedule/                         # Backup plan logic: ScheduleRowReader, BackupPlanBuilder,
│                                     # BackupPlanWriter, DailyCronExpression, PlanChange, Exception/
├── CustomFields/
│   └── CustomFieldSets/              # *.json custom field set definitions (+ README)
├── Services/
│   ├── SettingsInterface.php
│   └── Settings.php                  # Reads plugin configuration via SystemConfigService
├── Setup/
│   ├── Setup.php                     # install / update / uninstall orchestration
│   └── CustomFieldInstaller.php      # Creates/removes custom field sets from JSON
└── Resources/
    ├── app/administration/src/module/muckisafe-backup-plan/  # Settings → Plugins module (page, timeline component, helper/, snippets)
    └── config/
        ├── config.xml                # Plugin configuration (admin)
        ├── plugin.png
        └── services.xml              # DI (XML, no attributes)
tests/
├── TestBootstrap.php                 # Integration bootstrap (TestBootstrapper, TEST_DATABASE_URL)
├── UnitTestBootstrap.php             # Unit bootstrap (autoloader only)
├── Services/SettingsTest.php
├── Schedule/, Controller/            # Unit tests (no database)
├── Integration/                      # DB tests, need a booting TestBootstrapper
└── administration/*.test.mjs         # Node tests for the JS helpers (timeline-math, conflicts)
```

## Backup planner
- Config key `MuckiSafePlugin.config.scheduleBufferMinutes` (default 15).
- Runtime per schedule = maximum of the last 5 measured runs, min 60 s, 30 min estimate if none.
- Only daily crons (`M H * * *`) are draggable; other crons and queued/running schedules are locked.
- Facility runs one backup at a time and skips overlapping ones; the planner warns "will be skipped".
- Admin module `muckisafe-backup-plan` is a settingsItem under Settings → Plugins (like facility).

## Conventions
- New config keys: add a case to `Core\ConfigPath` (prefix `MuckiSafePlugin.config.`) and a field
  to `config.xml`; `SettingsTest::testConfigPathUsesPluginNamespace` guards the prefix.
- Services are registered in `services.xml`; consumers depend on `SettingsInterface`.
- Unit tests that need no database go into `phpunit.unit.xml` as well.
- On Muckidrive the Checkout bundle is disabled — do not depend on Checkout/Storefront entities.

## Commands

```bash
ddev exec bin/console plugin:refresh
ddev exec bin/console plugin:install --activate MuckiSafePlugin
ddev exec vendor/bin/phpunit --configuration=custom/static-plugins/MuckiSafePlugin/phpunit.unit.xml
node --test custom/static-plugins/MuckiSafePlugin/tests/administration/*.test.mjs   # JS helpers (directory form fails on Node 24)
```

Known limitation: `tests/Integration` needs a booting TestBootstrapper, which currently does not
boot with PHPUnit 9.6, so these tests are not run via the unit configuration.
