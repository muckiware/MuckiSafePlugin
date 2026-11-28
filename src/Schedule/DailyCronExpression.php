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

class DailyCronExpression
{
    /**
     * @return array{hour: int, minute: int}|null null when the cron is not a plain daily "M H * * *"
     */
    public static function parse(string $cron): ?array
    {
        $parts = preg_split('/\s+/', trim($cron));
        if ($parts === false || count($parts) !== 5) {
            return null;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;
        if ($day !== '*' || $month !== '*' || $weekday !== '*') {
            return null;
        }
        if (preg_match('/^\d{1,2}$/', $minute) !== 1 || preg_match('/^\d{1,2}$/', $hour) !== 1) {
            return null;
        }
        if ((int)$minute > 59 || (int)$hour > 23) {
            return null;
        }

        return ['hour' => (int)$hour, 'minute' => (int)$minute];
    }

    /**
     * Returns the cron's start time as HH:MM in the display timezone on the given date (Y-m-d).
     *
     * @throws \InvalidArgumentException when the cron is not a daily expression
     */
    public static function toDisplayStartTime(
        string $cron,
        string $date,
        \DateTimeZone $schedule,
        \DateTimeZone $display
    ): string {
        $parsed = self::parse($cron);
        if ($parsed === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a daily cron expression.', $cron));
        }

        $moment = new \DateTimeImmutable(
            sprintf('%s %02d:%02d:00', $date, $parsed['hour'], $parsed['minute']),
            $schedule
        );

        return $moment->setTimezone($display)->format('H:i');
    }

    /**
     * Converts an HH:MM start time in the display timezone into a daily cron in the schedule timezone.
     */
    public static function fromDisplayStartTime(
        string $startTime,
        string $date,
        \DateTimeZone $display,
        \DateTimeZone $schedule
    ): string {
        $moment = (new \DateTimeImmutable(sprintf('%s %s:00', $date, $startTime), $display))
            ->setTimezone($schedule);

        return sprintf('%d %d * * *', (int)$moment->format('i'), (int)$moment->format('G'));
    }
}
