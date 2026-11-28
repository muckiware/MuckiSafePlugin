# Changelog

All notable changes to this project will be documented in this file.

### [0.1.0]

### Added
- Plugin skeleton with install, update and uninstall handling
- Dependency on `muckiware/facility-plugin` `^0.11.3`
- `Active` switch in the plugin configuration
- `Core\ConfigPath` enum holding all configuration keys
- `Services\SettingsInterface` and `Services\Settings` reading the plugin configuration
- `Setup\CustomFieldInstaller` installing custom field sets from JSON definitions in
  `src/CustomFields/CustomFieldSets/`
- Backup plan administration module (Settings → Plugins) with a 24-hour timeline to move daily
  backup schedules by drag and drop, including overlap warnings ("will be skipped") and day navigation
- `Schedule\` services building the plan from facility schedules, detecting daily cron expressions and
  saving changes atomically
- API `GET` / `POST /api/_action/muckisafe/backup-plan` (ACL `muwa_backup_repository:read` / `:update`)
- Setting `scheduleBufferMinutes` (default `15`) as safety margin between backups
- PHPStan, PHPUnit (unit and integration configuration) and GitHub workflows for testing,
  gitleaks and releases
