import './component/muckisafe-backup-plan-timeline';
import './page/muckisafe-backup-plan-index';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('muckisafe-backup-plan', {

    // 'core' is required for a first-level main menu entry: the module factory drops
    // top-level navigation entries of 'plugin' modules (same approach as muwa-facility-menu).
    type: 'core',
    name: 'muckisafeBackupPlan',
    title: 'muckisafe-backup-plan.general.mainMenuLabel',
    description: 'muckisafe-backup-plan.general.description',
    color: '#c04d01',
    icon: 'regular-calendar',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },
    routes: {
        index: {
            component: 'muckisafe-backup-plan-index',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index.plugins',
                privilege: 'muwa_backup_repository.viewer',
            },
        },
    },
    navigation: [
        {
            id: 'muckisafe-backup-plan',
            label: 'muckisafe-backup-plan.general.mainMenuLabel',
            color: '#c04d01',
            icon: 'regular-calendar',
            path: 'muckisafe.backup.plan.index',
            position: 15,
            privilege: 'muwa_backup_repository.viewer',
        },
    ],
    settingsItem: [
        {
            name: 'muckisafe-backup-plan-index',
            to: 'muckisafe.backup.plan.index',
            group: 'plugins',
            icon: 'regular-calendar',
            backgroundEnabled: true,
            privilege: 'muwa_backup_repository.viewer',
            label: 'muckisafe-backup-plan.general.mainMenuLabel',
        },
    ],
});
