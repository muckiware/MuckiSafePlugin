# MuckiSafePlugin
[![Latest Stable Version](http://poser.pugx.org/muckiware/muckisafe-plugin/v)](https://packagist.org/packages/muckiware/muckisafe-plugin)
[![PHP Version Require](http://poser.pugx.org/muckiware/muckisafe-plugin/require/php)](https://packagist.org/packages/muckiware/muckisafe-plugin)
[![Shopware](https://img.shields.io/badge/Shopware-6.6%20%7C%206.7-189eff.svg)](https://www.shopware.com/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Muckiware MuckiSafe Plugin for Shopware 6. An extension built specifically for MuckiSafe instances,
used together with [muckiware/facility-plugin](https://github.com/muckiware/MuckiFacilityPlugin).

## Requirements
- Shopware 6.6.10.14 or greater, or 6.7.6.0 or greater
- PHP 8.3.x or greater
- [muckiware/facility-plugin](https://packagist.org/packages/muckiware/facility-plugin) `^0.11.3` (installed automatically by Composer)

## Installation
```shell
composer require muckiware/muckisafe-plugin
bin/console plugin:refresh
bin/console plugin:install --activate MuckiFacilityPlugin
bin/console plugin:install --activate MuckiSafePlugin
bin/console cache:clear
```

Update:
```shell
bin/console plugin:update MuckiSafePlugin
bin/console cache:clear
```

## Configuration
Administration → Settings → Extensions → **Mucki Safe Plugin**

| Setting | Description | Default |
|---|---|---|
| Active | Enables the plugin | `false` |
| Backup planner buffer (minutes) | Safety margin added to each backup runtime when the planner checks for overlaps (`MuckiSafePlugin.config.scheduleBufferMinutes`) | `15` |

## Backup planner
Administration → Settings → Plugins → **Backup Planner**

The planner shows the backup schedules of the facility plugin on a 24-hour timeline. Daily backups
can be moved by drag and drop (or with the keyboard); changes are saved atomically in one step.

- Only daily schedules (cron `M H * * *`) can be moved. Other cron expressions are shown but locked.
  Schedules that are queued or currently running are locked as well.
- The runtime of a backup is the maximum of its last 5 measured runs, but at least 60 seconds. If no
  run has been measured yet, 30 minutes are assumed. The buffer setting is added on top.
- The facility runs one backup at a time. A backup that starts while another one is still running is
  skipped, so the planner warns "will be skipped" for overlapping backups and asks for explicit
  confirmation before saving.

API: `GET` / `POST /api/_action/muckisafe/backup-plan`, protected by the ACL privileges
`muwa_backup_repository:read` and `muwa_backup_repository:update`.

## Custom fields
JSON files in `src/CustomFields/CustomFieldSets/` are installed as custom field sets on install and
update, and removed on uninstall (unless user data is kept). See the
[format description](src/CustomFields/CustomFieldSets/README.md).

## Development

### PHPStan
```shell
PROJECT_ROOT=/var/www/html vendor/bin/phpstan analyse --no-progress -c custom/static-plugins/MuckiSafePlugin/phpstan.neon
```

### PHPUnit
```shell
# Unit tests (no database required)
vendor/bin/phpunit --configuration=custom/static-plugins/MuckiSafePlugin/phpunit.unit.xml

# All tests (requires TEST_DATABASE_URL)
vendor/bin/phpunit --configuration=custom/static-plugins/MuckiSafePlugin
```

### Administration helper tests
```shell
node --test custom/static-plugins/MuckiSafePlugin/tests/administration/*.test.mjs
```

## Changelog
See [CHANGELOG.md](CHANGELOG.md)

## License
MIT, see [LICENSE](LICENSE)
