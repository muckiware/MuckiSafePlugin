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
namespace MuckiSafePlugin\tests\Schedule;

use MuckiFacilityPlugin\Schedule\ScheduleCalculator;
use MuckiSafePlugin\Schedule\BackupPlanBuilder;
use MuckiSafePlugin\Schedule\ScheduleRow;
use PHPUnit\Framework\TestCase;

class BackupPlanBuilderTest extends TestCase
{
    private const DATE = '2026-11-27';

    private function builder(): BackupPlanBuilder
    {
        return new BackupPlanBuilder(new ScheduleCalculator());
    }

    private function row(
        string $cron,
        ?int $maxDuration = 600,
        int $samples = 5,
        string $status = 'scheduled',
        bool $enabled = true,
        string $timezone = 'Europe/Berlin'
    ): ScheduleRow {
        return new ScheduleRow('aabbccdd', 'Repo', $enabled, $status, $cron, $timezone, $maxDuration, $samples);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ScheduleRow $row, string $date = self::DATE): array
    {
        $plan = $this->builder()->build([$row], $date, new \DateTimeZone('Europe/Berlin'), 10);

        return $plan['items'][0];
    }

    public function testDailyScheduleHasOneOccurrenceAndStartTime(): void
    {
        $plan = $this->builder()->build(
            [$this->row('0 2 * * *', 2700, 5)],
            self::DATE,
            new \DateTimeZone('Europe/Berlin'),
            15
        );

        static::assertSame('2026-11-26T23:00:00+00:00', $plan['windowStart']);
        static::assertSame('2026-11-27T23:00:00+00:00', $plan['windowEnd']);
        static::assertSame(15, $plan['bufferMinutes']);
        static::assertSame(self::DATE, $plan['date']);
        static::assertSame('Europe/Berlin', $plan['timezone']);

        $item = $plan['items'][0];
        static::assertTrue($item['daily']);
        static::assertSame('02:00', $item['startTime']);
        static::assertFalse($item['durationEstimated']);
        static::assertSame(5, $item['durationSampleCount']);
        static::assertSame(2700, $item['durationSeconds']);
        static::assertSame(
            [['start' => '2026-11-27T01:00:00+00:00', 'end' => '2026-11-27T01:45:00+00:00']],
            $item['occurrences']
        );
    }

    public function testRunCrossingMidnightFromPreviousDayIsIncluded(): void
    {
        $item = $this->item($this->row('30 23 * * *', 3600));

        static::assertSame(
            ['2026-11-26T22:30:00+00:00', '2026-11-27T22:30:00+00:00'],
            array_column($item['occurrences'], 'start')
        );
    }

    public function testNonDailyScheduleListsAllRunsAndHasNoStartTime(): void
    {
        $item = $this->item($this->row('0 */6 * * *', 600));

        static::assertCount(4, $item['occurrences']);
        static::assertFalse($item['daily']);
        static::assertNull($item['startTime']);
    }

    public function testMissingDurationFallsBackToEstimate(): void
    {
        $item = $this->item($this->row('0 2 * * *', null, 0));

        static::assertSame(BackupPlanBuilder::ESTIMATED_DURATION_SECONDS, $item['durationSeconds']);
        static::assertSame(1800, $item['durationSeconds']);
        static::assertTrue($item['durationEstimated']);
        static::assertSame(0, $item['durationSampleCount']);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function statusProvider(): array
    {
        return [
            'queued' => ['queued', false],
            'running' => ['running', false],
            'scheduled' => ['scheduled', true],
            'failed' => ['failed', true],
            'inactive' => ['inactive', true],
        ];
    }

    /**
     * @dataProvider statusProvider
     */
    public function testQueuedAndRunningAreNotEditable(string $status, bool $editable): void
    {
        $item = $this->item($this->row('0 2 * * *', 600, 1, $status));

        static::assertSame($editable, $item['editable']);
        static::assertSame($status, $item['status']);
    }

    public function testDisabledScheduleStillHasOccurrences(): void
    {
        $item = $this->item($this->row('0 2 * * *', 600, 1, 'inactive', false));

        static::assertFalse($item['enabled']);
        static::assertCount(1, $item['occurrences']);
    }

    public function testInvalidCronYieldsLockedItemWithoutOccurrences(): void
    {
        $item = $this->item($this->row('not a cron'));

        static::assertSame([], $item['occurrences']);
        static::assertFalse($item['editable']);
        static::assertFalse($item['daily']);
        static::assertNull($item['startTime']);
    }

    public function testInvalidTimezoneYieldsLockedItemWithoutOccurrences(): void
    {
        $item = $this->item($this->row('0 2 * * *', 600, 1, 'scheduled', true, 'Mars/Olympus'));

        static::assertSame([], $item['occurrences']);
        static::assertFalse($item['editable']);
    }

    public function testEveryMinuteCronIsCappedAt1440Occurrences(): void
    {
        $item = $this->item($this->row('* * * * *', 60));

        static::assertCount(BackupPlanBuilder::MAX_OCCURRENCES, $item['occurrences']);
        static::assertCount(1440, $item['occurrences']);
    }

    public function testSpringForwardDayWindowIs23Hours(): void
    {
        $plan = $this->builder()->build([], '2026-03-29', new \DateTimeZone('Europe/Berlin'), 10);

        $seconds = strtotime($plan['windowEnd']) - strtotime($plan['windowStart']);
        static::assertSame(23 * 3600, $seconds);
    }
}
