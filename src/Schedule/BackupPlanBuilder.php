<?php declare(strict_types=1);
/**
 * MuckiSafePlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiSafe
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiSafePlugin\Schedule;

use MuckiFacilityPlugin\Schedule\ScheduleCalculator;

class BackupPlanBuilder
{
    public const ESTIMATED_DURATION_SECONDS = 1800;
    public const MIN_DURATION_SECONDS = 60;
    public const MAX_OCCURRENCES = 1440;
    public const LOCKED_STATUSES = ['queued', 'running'];

    public function __construct(protected ScheduleCalculator $calculator)
    {
    }

    /**
     * @param list<ScheduleRow> $rows
     *
     * @return array<string, mixed>
     */
    public function build(array $rows, string $date, \DateTimeZone $display, int $bufferMinutes): array
    {
        $windowStart = new \DateTimeImmutable($date . ' 00:00:00', $display);
        $windowEnd = $windowStart->modify('+1 day');

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->buildItem($row, $date, $display, $windowStart, $windowEnd);
        }

        return [
            'date' => $date,
            'timezone' => $display->getName(),
            'windowStart' => $this->iso($windowStart),
            'windowEnd' => $this->iso($windowEnd),
            'bufferMinutes' => $bufferMinutes,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItem(
        ScheduleRow $row,
        string $date,
        \DateTimeZone $display,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd
    ): array {
        $estimated = $row->maxDurationSeconds === null;
        $duration = $estimated
            ? self::ESTIMATED_DURATION_SECONDS
            : max(self::MIN_DURATION_SECONDS, (int)$row->maxDurationSeconds);

        $valid = true;
        $occurrences = [];
        try {
            $occurrences = $this->occurrences($row, $duration, $windowStart, $windowEnd);
        } catch (\InvalidArgumentException) {
            $valid = false;
        }

        $daily = $valid && DailyCronExpression::parse($row->cronExpression) !== null;
        $locked = in_array($row->status, self::LOCKED_STATUSES, true);

        return [
            'repositoryId' => $row->repositoryId,
            'name' => $row->name,
            'enabled' => $row->enabled,
            'status' => $row->status,
            'editable' => $valid && !$locked,
            'cronExpression' => $row->cronExpression,
            'scheduleTimezone' => $row->timezone,
            'daily' => $daily,
            'startTime' => $daily ? $this->startTime($row, $date, $display) : null,
            'durationSeconds' => $duration,
            'durationEstimated' => $estimated,
            'durationSampleCount' => $row->durationSampleCount,
            'occurrences' => $occurrences,
        ];
    }

    /**
     * @return list<array{start: string, end: string}>
     *
     * @throws \InvalidArgumentException on invalid cron expression or timezone
     */
    private function occurrences(
        ScheduleRow $row,
        int $duration,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd
    ): array {
        $occurrences = [];
        $from = $windowStart->modify(sprintf('-%d seconds', $duration));

        while (count($occurrences) < self::MAX_OCCURRENCES) {
            $start = $this->calculator->next($row->cronExpression, $row->timezone, $from);
            if ($start >= $windowEnd) {
                break;
            }
            $occurrences[] = [
                'start' => $this->iso($start),
                'end' => $this->iso($start->modify(sprintf('+%d seconds', $duration))),
            ];
            $from = $start;
        }

        return $occurrences;
    }

    private function startTime(ScheduleRow $row, string $date, \DateTimeZone $display): string
    {
        return DailyCronExpression::toDisplayStartTime(
            $row->cronExpression,
            $date,
            new \DateTimeZone($row->timezone),
            $display
        );
    }

    private function iso(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
