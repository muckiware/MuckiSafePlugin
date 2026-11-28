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

class ScheduleRow
{
    public function __construct(
        public readonly string $repositoryId,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly string $status,
        public readonly string $cronExpression,
        public readonly string $timezone,
        public readonly ?int $maxDurationSeconds,
        public readonly int $durationSampleCount
    ) {
    }
}
