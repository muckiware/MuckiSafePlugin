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
| Companion plugin | `muckiware/facility-plugin` (`MuckiFacilityPlugin`) |

## Structure

```
src/
├── MuckiSafePlugin.php               # Plugin class, delegates lifecycle to Setup
├── Core/
│   └── ConfigPath.php                # Enum of all configuration keys
├── CustomFields/
│   └── CustomFieldSets/              # *.json custom field set definitions (+ README)
├── Services/
│   ├── SettingsInterface.php
│   └── Settings.php                  # Reads plugin configuration via SystemConfigService
├── Setup/
│   ├── Setup.php                     # install / update / uninstall orchestration
│   └── CustomFieldInstaller.php      # Creates/removes custom field sets from JSON
└── Resources/config/
    ├── config.xml                    # Plugin configuration (admin)
    ├── plugin.png
    └── services.xml                  # DI (XML, no attributes)
tests/
├── TestBootstrap.php                 # Integration bootstrap (TestBootstrapper, TEST_DATABASE_URL)
├── UnitTestBootstrap.php             # Unit bootstrap (autoloader only)
└── Services/SettingsTest.php
```

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
```
