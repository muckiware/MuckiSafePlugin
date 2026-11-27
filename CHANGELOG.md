# Changelog

All notable changes to this project will be documented in this file.

### [0.1.0]

### Added
- Plugin skeleton with install, update and uninstall handling
- `Active` switch in the plugin configuration
- `Core\ConfigPath` enum holding all configuration keys
- `Services\SettingsInterface` and `Services\Settings` reading the plugin configuration
- `Setup\CustomFieldInstaller` installing custom field sets from JSON definitions in
  `src/CustomFields/CustomFieldSets/`
- PHPStan, PHPUnit (unit and integration configuration) and GitHub workflows for testing,
  gitleaks and releases
