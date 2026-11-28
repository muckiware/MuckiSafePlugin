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

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Schedule\ScheduleConfiguration;
use MuckiSafePlugin\Schedule\Exception\PlanValidationException;
use MuckiSafePlugin\Schedule\Exception\ScheduleLockedException;
use MuckiSafePlugin\Schedule\Exception\ScheduleNotFoundException;

class BackupPlanWriter
{
    public function __construct(
        protected Connection $connection,
        protected ScheduleRowReader $reader,
        protected ScheduleConfiguration $configuration
    ) {
    }

    /**
     * Applies all changes in one transaction; any failure rolls everything back.
     *
     * @param list<PlanChange> $changes
     *
     * @throws PlanValidationException
     * @throws ScheduleNotFoundException
     * @throws ScheduleLockedException
     */
    public function apply(string $date, \DateTimeZone $display, array $changes): void
    {
        $this->connection->transactional(function () use ($date, $display, $changes): void {
            foreach ($changes as $change) {
                $this->applyChange($date, $display, $change);
            }
        });
    }

    protected function applyChange(string $date, \DateTimeZone $display, PlanChange $change): void
    {
        $row = $this->reader->fetchForUpdate($change->repositoryId);
        if ($row === null) {
            throw new ScheduleNotFoundException(sprintf('No schedule for repository "%s".', $change->repositoryId));
        }
        if (in_array($row->status, BackupPlanBuilder::LOCKED_STATUSES, true)) {
            throw new ScheduleLockedException(sprintf('Schedule of "%s" is currently %s.', $row->name, $row->status));
        }

        $cron = $row->cronExpression;
        if ($change->startTime !== null) {
            if (DailyCronExpression::parse($row->cronExpression) === null) {
                throw new PlanValidationException(
                    sprintf('Schedule of "%s" is not a daily schedule; its start time cannot be moved.', $row->name)
                );
            }
            $cron = DailyCronExpression::fromDisplayStartTime(
                $change->startTime,
                $date,
                $display,
                new \DateTimeZone($row->timezone)
            );
        }

        $this->configuration->configure(
            $row->repositoryId,
            $change->enabled ?? $row->enabled,
            $cron,
            $row->timezone
        );
    }
}
