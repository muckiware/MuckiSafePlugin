import template from './muckisafe-backup-plan-index.html.twig';
import './muckisafe-backup-plan-index.scss';
import { clipSegments, dailyOccurrences, formatWallTime, toMinutes } from '../../helper/timeline-math';
import { findConflicts } from '../../helper/conflicts';

const { Component, Mixin } = Shopware;

const API_PATH = '/_action/muckisafe/backup-plan';
const MS_PER_MINUTE = 60000;
const MS_PER_DAY = 86400000;

/**
 * Backup schedule planner: loads the plan of one day, keeps moves and toggles as local unsaved
 * changes (one change per repository) and saves them in one request. The view items are the single
 * source of truth for the timeline; conflicts are derived from them.
 */
Component.register('muckisafe-backup-plan-index', {

    template,

    compatConfig: Shopware.compatConfig,

    inject: ['acl'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    data() {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        return {
            timezone,
            today: todayIn(timezone),
            date: todayIn(timezone),
            plan: null,
            viewItems: [],
            changes: {},
            isLoading: false,
            isSaving: false,
            httpClient: null,
            showConflictModal: false,
            showUnsavedModal: false,
            showLeaveModal: false,
            pendingDate: null,
            pendingLeave: null,
            afterSave: null,
        };
    },

    computed: {
        canEdit() {
            return this.acl.can('muwa_backup_repository.editor');
        },

        dayLength() {
            if (this.plan === null) {
                return 1440;
            }

            return (Date.parse(this.plan.windowEnd) - Date.parse(this.plan.windowStart)) / MS_PER_MINUTE;
        },

        hasChanges() {
            return Object.keys(this.changes).length > 0;
        },

        conflicts() {
            if (this.plan === null) {
                return [];
            }

            return findConflicts(this.viewItems, this.plan.bufferMinutes);
        },

        skippedWarnings() {
            const names = Object.fromEntries(this.viewItems.map((item) => [item.repositoryId, item.name]));
            const skipped = this.conflicts
                .map((conflict) => names[conflict.skippedId])
                .filter((name) => name !== undefined);

            return [...new Set(skipped)]
                .map((name) => this.$t('muckisafe-backup-plan.timeline.skippedWarning', { name }));
        },

        dateLabel() {
            const [year, month, day] = this.date.split('-').map(Number);

            return new Intl.DateTimeFormat(this.$i18n?.locale || undefined, {
                timeZone: 'UTC',
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
            }).format(new Date(Date.UTC(year, month - 1, day)));
        },
    },

    created() {
        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        this.loadPlan();
    },

    beforeRouteLeave(to, from, next) {
        if (!this.hasChanges) {
            next();
            return;
        }
        this.pendingLeave = next;
        this.showLeaveModal = true;
    },

    methods: {
        getApiHeader() {
            return {
                Accept: 'application/vnd.api+json',
                Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                'Content-Type': 'application/json',
            };
        },

        getErrorMessage(exception) {
            const detail = exception?.response?.data?.errors?.[0]?.detail;

            return detail || exception?.message || String(exception);
        },

        async loadPlan() {
            this.isLoading = true;
            try {
                const response = await this.httpClient.get(API_PATH, {
                    params: { date: this.date, timezone: this.timezone },
                    headers: this.getApiHeader(),
                });
                this.applyPlan(response.data);
            } catch (exception) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: `${this.$tc('muckisafe-backup-plan.notification.loadError')} ${this.getErrorMessage(exception)}`,
                });
            } finally {
                this.isLoading = false;
            }
        },

        applyPlan(plan) {
            this.plan = plan;
            this.changes = {};
            this.viewItems = sortByStart(plan.items.map((item) => this.buildViewItem(item)));
        },

        /**
         * Converts an API item into a timeline item. Occurrences stay unclipped (conflicts need
         * the runs started the previous day), segments are the clipped visible parts.
         */
        buildViewItem(item) {
            const occurrences = item.occurrences.map(({ start, end }) => ({
                start: toMinutes(start, this.plan.windowStart),
                end: toMinutes(end, this.plan.windowStart),
            }));
            const firstInWindow = occurrences.find(({ start }) => start >= 0 && start < this.dayLength);
            const startMinute = item.daily && firstInWindow !== undefined ? firstInWindow.start : null;

            return {
                ...item,
                durationMinutes: item.durationSeconds / 60,
                occurrences,
                segments: clipSegments(occurrences, this.dayLength),
                startMinute,
                original: { enabled: item.enabled, startMinute, occurrences },
                dirty: false,
            };
        },

        findItem(repositoryId) {
            return this.viewItems.find((item) => item.repositoryId === repositoryId);
        },

        onMove(repositoryId, startMinute) {
            const item = this.findItem(repositoryId);
            if (item === undefined || !this.canEdit || !item.editable || !item.daily) {
                return;
            }
            item.startMinute = startMinute;
            item.occurrences = startMinute === item.original.startMinute
                ? item.original.occurrences
                : dailyOccurrences(startMinute, item.durationMinutes, this.dayLength);
            item.segments = clipSegments(item.occurrences, this.dayLength);
            this.updateChange(item);
        },

        onToggle(repositoryId, enabled) {
            const item = this.findItem(repositoryId);
            if (item === undefined || !this.canEdit || !item.editable) {
                return;
            }
            item.enabled = enabled;
            this.updateChange(item);
        },

        /**
         * Rebuilds the change of one repository from the difference to the server state, so a
         * move back to the original time or a double toggle leaves no change behind.
         */
        updateChange(item) {
            const change = { repositoryId: item.repositoryId };
            if (item.enabled !== item.original.enabled) {
                change.enabled = item.enabled;
            }
            if (item.startMinute !== item.original.startMinute) {
                change.startTime = formatWallTime(this.plan.windowStart, item.startMinute, this.timezone);
            }
            const changes = { ...this.changes };
            if (Object.keys(change).length > 1) {
                changes[item.repositoryId] = change;
            } else {
                delete changes[item.repositoryId];
            }
            this.changes = changes;
            item.dirty = changes[item.repositoryId] !== undefined;
        },

        buildSavePayload() {
            return {
                date: this.date,
                timezone: this.timezone,
                changes: Object.values(this.changes),
            };
        },

        onSave() {
            if (!this.hasChanges || !this.canEdit) {
                return;
            }
            if (this.conflicts.length > 0) {
                this.showConflictModal = true;
                return;
            }
            this.savePlan();
        },

        onConfirmConflicts() {
            this.showConflictModal = false;
            this.savePlan();
        },

        onCancelConflicts() {
            this.showConflictModal = false;
            this.afterSave = null;
        },

        async savePlan() {
            const afterSave = this.afterSave;
            this.afterSave = null;
            this.isSaving = true;
            try {
                await this.httpClient.post(API_PATH, this.buildSavePayload(), { headers: this.getApiHeader() });
            } catch (exception) {
                this.isSaving = false;
                this.handleSaveError(exception);
                return;
            }
            this.isSaving = false;
            this.createNotificationSuccess({
                title: this.$tc('global.default.success'),
                message: this.$tc('muckisafe-backup-plan.notification.saveSuccess'),
            });
            if (afterSave !== null) {
                afterSave();
                return;
            }
            await this.loadPlan();
        },

        handleSaveError(exception) {
            if (exception?.response?.status === 409) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('muckisafe-backup-plan.notification.lockedError'),
                });
                this.loadPlan();
                return;
            }
            this.createNotificationError({
                title: this.$tc('global.default.error'),
                message: `${this.$tc('muckisafe-backup-plan.notification.saveError')} ${this.getErrorMessage(exception)}`,
            });
        },

        onDiscard() {
            this.loadPlan();
        },

        onPreviousDay() {
            this.requestDate(shiftDate(this.date, -1));
        },

        onNextDay() {
            this.requestDate(shiftDate(this.date, 1));
        },

        onToday() {
            this.requestDate(this.today);
        },

        /**
         * Changes are never carried over to another day: with unsaved changes the user first
         * chooses between saving and discarding them.
         */
        requestDate(date) {
            if (date === this.date) {
                return;
            }
            if (!this.hasChanges) {
                this.goToDate(date);
                return;
            }
            this.pendingDate = date;
            this.showUnsavedModal = true;
        },

        goToDate(date) {
            this.date = date;
            this.loadPlan();
        },

        onUnsavedSave() {
            const date = this.pendingDate;
            this.closeUnsavedModal();
            this.afterSave = () => this.goToDate(date);
            this.onSave();
        },

        onUnsavedDiscard() {
            const date = this.pendingDate;
            this.closeUnsavedModal();
            this.goToDate(date);
        },

        closeUnsavedModal() {
            this.showUnsavedModal = false;
            this.pendingDate = null;
        },

        onLeaveConfirm() {
            const next = this.pendingLeave;
            this.showLeaveModal = false;
            this.pendingLeave = null;
            this.changes = {};
            next();
        },

        onLeaveCancel() {
            const next = this.pendingLeave;
            this.showLeaveModal = false;
            this.pendingLeave = null;
            next(false);
        },
    },
});

/**
 * @param {string} timeZone IANA time zone
 * @returns {string} today's date (YYYY-MM-DD) in that time zone
 */
function todayIn(timeZone) {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

/**
 * @param {string} date YYYY-MM-DD
 * @param {number} days
 * @returns {string} YYYY-MM-DD
 */
function shiftDate(date, days) {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day) + days * MS_PER_DAY).toISOString().slice(0, 10);
}

/**
 * Rows are sorted by their first visible start, items without a visible run last, then by name.
 * Sorting happens on load only, so rows do not jump while dragging.
 */
function sortByStart(items) {
    const key = (item) => {
        if (item.startMinute !== null) {
            return item.startMinute;
        }
        const segment = item.segments[0];

        return segment === undefined ? Number.POSITIVE_INFINITY : segment.from;
    };

    return [...items].sort((a, b) => (key(a) - key(b)) || a.name.localeCompare(b.name));
}
