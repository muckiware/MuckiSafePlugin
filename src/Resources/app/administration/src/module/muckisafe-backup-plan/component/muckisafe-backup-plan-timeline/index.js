import template from './muckisafe-backup-plan-timeline.html.twig';
import './muckisafe-backup-plan-timeline.scss';
import { formatWallTime, snapMinute } from '../../helper/timeline-math';

const { Component } = Shopware;

/** Drag and keyboard grid in minutes. */
const GRID_MINUTES = 15;

/** Hours between two labels on the hour axis. */
const AXIS_LABEL_HOURS = 3;

/** Number of block colours defined in the stylesheet (--color-0 … --color-3). */
const COLOR_COUNT = 4;

/**
 * Gantt style day timeline of the backup plan: a "Load" row with the conflict zones, then one row
 * per backup with its toggle, label and blocks. Pure view component: it never changes the items,
 * it emits `move` (live while dragging and on arrow keys) and `toggle` (switch) for the page.
 * During a drag the page updates the item from each `move`, so blocks follow the props; the
 * local drag state only tracks the pointer and the tooltip.
 *
 * Usage:
 * <muckisafe-backup-plan-timeline :items="viewItems" :conflicts="conflicts" :day-length="dayLength"
 *     :window-start="plan.windowStart" :timezone="timezone" :can-edit="canEdit"
 *     @move="onMove" @toggle="onToggle" />
 */
Component.register('muckisafe-backup-plan-timeline', {

    template,

    compatConfig: Shopware.compatConfig,

    inject: ['feature'],

    emits: ['move', 'toggle'],

    props: {
        items: {
            type: Array,
            required: true,
        },
        conflicts: {
            type: Array,
            required: false,
            default: () => [],
        },
        dayLength: {
            type: Number,
            required: true,
        },
        windowStart: {
            type: String,
            required: true,
        },
        timezone: {
            type: String,
            required: true,
        },
        canEdit: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            V6_5_0_0: false,
            V6_6_0_0: false,
            drag: null,
        };
    },

    created() {
        this.V6_6_0_0 = this.feature.isActive('V6_6_0_0');
        this.V6_5_0_0 = this.feature.isActive('V6_5_0_0') && !this.V6_6_0_0;
    },

    beforeUnmount() {
        this.endDrag();
    },

    watch: {
        itemIds(ids) {
            if (this.drag !== null && !ids.includes(this.drag.repositoryId)) {
                this.endDrag();
            }
        },
    },

    computed: {
        isV6600() {
            return this.V6_6_0_0;
        },

        isV6500() {
            return this.V6_5_0_0;
        },

        hourCount() {
            return this.dayLength / 60;
        },

        trackStyle() {
            return { backgroundSize: `${100 / this.hourCount}% 100%` };
        },

        axisLabels() {
            const labels = [];
            for (let minute = 0; minute < this.dayLength; minute += AXIS_LABEL_HOURS * 60) {
                labels.push({
                    minute,
                    label: String(Number(formatWallTime(this.windowStart, minute, this.timezone).slice(0, 2))),
                });
            }
            labels.push({ minute: this.dayLength, label: '24' });

            return labels;
        },

        itemIds() {
            return this.items.map((item) => item.repositoryId);
        },

        itemNames() {
            return Object.fromEntries(this.items.map((item) => [item.repositoryId, item.name]));
        },

        skippedWarnings() {
            const names = this.conflicts
                .map((conflict) => this.itemNames[conflict.skippedId])
                .filter((name) => name !== undefined);

            return [...new Set(names)]
                .map((name) => this.$t('muckisafe-backup-plan.timeline.skippedWarning', { name }));
        },
    },

    methods: {
        isDraggable(item) {
            return this.canEdit && item.editable && item.daily && item.startMinute !== null;
        },

        isLocked(item) {
            return !item.daily || !item.editable;
        },

        isConflicting(item) {
            return this.conflicts.some((c) => c.skippedId === item.repositoryId || c.otherId === item.repositoryId);
        },

        isSkipped(item) {
            return this.conflicts.some((c) => c.skippedId === item.repositoryId);
        },

        isDragging(item) {
            return this.drag !== null && this.drag.repositoryId === item.repositoryId;
        },

        positionStyle(from, to) {
            return {
                left: `${(from / this.dayLength) * 100}%`,
                width: `${((to - from) / this.dayLength) * 100}%`,
            };
        },

        blockClasses(item, index) {
            return {
                [`muckisafe-backup-plan-timeline__block--color-${index % COLOR_COUNT}`]: true,
                'is--draggable': this.isDraggable(item),
                'is--locked': this.isLocked(item),
                'is--disabled': !item.enabled,
                'is--estimated': Boolean(item.durationEstimated),
                'is--conflict': item.enabled && this.isConflicting(item),
                'is--dragging': this.isDragging(item),
                'is--dirty': Boolean(item.dirty),
            };
        },

        blockTitle(item) {
            const parts = [item.name, this.durationLabel(item)];
            if (item.enabled && this.isSkipped(item)) {
                parts.push(this.$t('muckisafe-backup-plan.timeline.skippedWarning', { name: item.name }));
            }
            if (this.isLocked(item) && item.editable) {
                parts.push(this.$t('muckisafe-backup-plan.timeline.locked'));
            }

            return parts.join(' · ');
        },

        startLabel(item) {
            if (item.daily && item.startMinute !== null) {
                return formatWallTime(this.windowStart, item.startMinute, this.timezone);
            }

            return item.cronExpression;
        },

        durationLabel(item) {
            const hours = Math.floor(item.durationMinutes / 60);
            const minutes = Math.round(item.durationMinutes % 60);
            let label = this.$t('muckisafe-backup-plan.timeline.durationMinutes', { minutes });
            if (hours > 0 && minutes > 0) {
                label = this.$t('muckisafe-backup-plan.timeline.durationHoursMinutes', { hours, minutes });
            } else if (hours > 0) {
                label = this.$t('muckisafe-backup-plan.timeline.durationHours', { hours });
            }

            return item.durationEstimated ? `~${label}` : label;
        },

        durationTitle(item) {
            if (item.durationEstimated) {
                return this.$t('muckisafe-backup-plan.timeline.estimateTooltip');
            }

            return this.$t('muckisafe-backup-plan.timeline.durationSamples', { count: item.durationSampleCount });
        },

        dragTooltipStyle() {
            return { left: `${(this.drag.minute / this.dayLength) * 100}%` };
        },

        dragTooltipText() {
            return formatWallTime(this.windowStart, this.drag.minute, this.timezone);
        },

        onToggle(item, enabled) {
            if (!this.canEdit || !item.editable) {
                return;
            }
            this.$emit('toggle', item.repositoryId, Boolean(enabled));
        },

        onPointerDown(item, event) {
            if (!this.isDraggable(item) || event.button !== 0) {
                return;
            }
            const track = event.currentTarget.closest('.muckisafe-backup-plan-timeline__track');
            event.preventDefault();
            track.setPointerCapture(event.pointerId);
            this.drag = {
                repositoryId: item.repositoryId,
                pointerId: event.pointerId,
                track,
                originX: event.clientX,
                originMinute: item.startMinute,
                minute: item.startMinute,
            };
        },

        onPointerMove(event) {
            if (!this.isActivePointer(event)) {
                return;
            }
            const width = this.drag.track.getBoundingClientRect().width;
            if (width <= 0) {
                return;
            }
            const delta = ((event.clientX - this.drag.originX) / width) * this.dayLength;
            const minute = snapMinute(this.drag.originMinute + delta, this.dayLength, GRID_MINUTES);
            if (minute === this.drag.minute) {
                return;
            }
            this.drag.minute = minute;
            this.$emit('move', this.drag.repositoryId, minute);
        },

        onPointerUp(event) {
            if (this.isActivePointer(event)) {
                this.endDrag();
            }
        },

        /**
         * Pointer cancelled or capture lost without a pointerup: revert the live moves.
         */
        onPointerCancel(event) {
            if (!this.isActivePointer(event)) {
                return;
            }
            const { repositoryId, originMinute, minute } = this.drag;
            this.endDrag();
            if (minute !== originMinute) {
                this.$emit('move', repositoryId, originMinute);
            }
        },

        isActivePointer(event) {
            return this.drag !== null && event.pointerId === this.drag.pointerId;
        },

        endDrag() {
            if (this.drag === null) {
                return;
            }
            const { track, pointerId } = this.drag;
            this.drag = null;
            if (track.hasPointerCapture(pointerId)) {
                track.releasePointerCapture(pointerId);
            }
        },

        onKeyDown(item, event) {
            if (!this.isDraggable(item) || this.drag !== null) {
                return;
            }
            const step = { ArrowLeft: -GRID_MINUTES, ArrowRight: GRID_MINUTES }[event.key];
            if (step === undefined) {
                return;
            }
            event.preventDefault();
            const minute = snapMinute(item.startMinute + step, this.dayLength, GRID_MINUTES);
            if (minute !== item.startMinute) {
                this.$emit('move', item.repositoryId, minute);
            }
        },
    },
});
