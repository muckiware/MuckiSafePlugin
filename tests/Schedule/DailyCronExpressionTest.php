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

use PHPUnit\Framework\TestCase;

use MuckiSafePlugin\Schedule\DailyCronExpression;

class DailyCronExpressionTest extends TestCase
{
    public function testParseAcceptsDailyCron(): void
    {
        static::assertSame(['hour' => 2, 'minute' => 30], DailyCronExpression::parse('30 2 * * *'));
        static::assertSame(['hour' => 0, 'minute' => 0], DailyCronExpression::parse(' 0  0 * * * '));
    }

    /**
     * @dataProvider nonDailyProvider
     */
    public function testParseRejectsNonDaily(string $cron): void
    {
        static::assertNull(DailyCronExpression::parse($cron));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDailyProvider(): array
    {
        return [
            'step' => ['0 */6 * * *'],
            'weekday' => ['0 2 * * 0'],
            'day of month' => ['0 2 1 * *'],
            'month' => ['0 2 * 1 *'],
            'list' => ['0,30 2 * * *'],
            'range' => ['0 2-4 * * *'],
            'alias' => ['@daily'],
            'minute out of range' => ['60 2 * * *'],
            'hour out of range' => ['0 24 * * *'],
            'six fields' => ['0 2 * * * *'],
        ];
    }

    public function testFromDisplayStartTimeConvertsTimezone(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $utc = new \DateTimeZone('UTC');

        static::assertSame('15 2 * * *', DailyCronExpression::fromDisplayStartTime('03:15', '2026-11-27', $berlin, $utc));
        static::assertSame('15 3 * * *', DailyCronExpression::fromDisplayStartTime('03:15', '2026-11-27', $berlin, $berlin));
    }

    public function testFromDisplayStartTimeOnSpringForwardGap(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        $cron = DailyCronExpression::fromDisplayStartTime('02:30', '2026-03-29', $berlin, $berlin);

        static::assertNotNull(DailyCronExpression::parse($cron));
        static::assertSame('30 3 * * *', $cron);
    }

    public function testToDisplayStartTimeConvertsTimezone(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $utc = new \DateTimeZone('UTC');

        static::assertSame('03:15', DailyCronExpression::toDisplayStartTime('15 2 * * *', '2026-11-27', $utc, $berlin));
        static::assertSame('04:15', DailyCronExpression::toDisplayStartTime('15 2 * * *', '2026-07-01', $utc, $berlin));
    }

    public function testToDisplayStartTimeThrowsForNonDaily(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DailyCronExpression::toDisplayStartTime('0 */6 * * *', '2026-11-27', new \DateTimeZone('UTC'), new \DateTimeZone('UTC'));
    }
}
