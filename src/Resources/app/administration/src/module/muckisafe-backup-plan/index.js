import './component/muckisafe-backup-plan-timeline';
import './page/muckisafe-backup-plan-index';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('muckisafe-backup-plan', {

    type: 'plugin',
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
