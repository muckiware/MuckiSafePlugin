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

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Schedule\ScheduleCalculator;
use MuckiFacilityPlugin\Schedule\ScheduleConfiguration;
use MuckiSafePlugin\Schedule\BackupPlanWriter;
use MuckiSafePlugin\Schedule\Exception\PlanValidationException;
use MuckiSafePlugin\Schedule\Exception\ScheduleLockedException;
use MuckiSafePlugin\Schedule\Exception\ScheduleNotFoundException;
use MuckiSafePlugin\Schedule\PlanChange;
use MuckiSafePlugin\Schedule\ScheduleRow;
use MuckiSafePlugin\Schedule\ScheduleRowReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

class BackupPlanWriterTest extends TestCase
{
    private const DATE = '2026-11-27';

    /** @var list<array<string, mixed>> */
    private array $executed = [];

    private Connection&MockObject $transactionConnection;

    private ScheduleRowReader&MockObject $reader;

    private BackupPlanWriter $writer;

    protected function setUp(): void
    {
        $this->executed = [];

        $this->transactionConnection = $this->createMock(Connection::class);
        $this->transactionConnection->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback) => $callback($this->transactionConnection));

        $configConnection = $this->createMock(Connection::class);
        $configConnection->method('fetchOne')->willReturn(1);
        $configConnection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->executed[] = $params;

                return 1;
            }
        );

        $this->reader = $this->createMock(ScheduleRowReader::class);
        $this->writer = new BackupPlanWriter(
            $this->transactionConnection,
            $this->reader,
            new ScheduleConfiguration($configConnection, new ScheduleCalculator())
        );
    }

    private function row(string $id, string $cron, string $status = 'scheduled', bool $enabled = true): ScheduleRow
    {
        return new ScheduleRow($id, 'Repo', $enabled, $status, $cron, 'UTC', null, 0);
    }

    public function testMovesDailyScheduleInItsOwnTimezone(): void
    {
        $id = Uuid::randomHex();
        $this->reader->method('fetchForUpdate')->willReturn($this->row($id, '0 2 * * *'));

        $this->writer->apply(self::DATE, new \DateTimeZone('Europe/Berlin'), [new PlanChange($id, null, '03:15')]);

        self::assertCount(1, $this->executed);
        self::assertSame('15 2 * * *', $this->executed[0]['cronExpression']);
        self::assertSame('UTC', $this->executed[0]['timezone']);
        self::assertSame(1, $this->executed[0]['enabled']);
    }

    public function testToggleKeepsCronExpression(): void
    {
        $id = Uuid::randomHex();
        $this->reader->method('fetchForUpdate')->willReturn($this->row($id, '0 */6 * * *'));

        $this->writer->apply(self::DATE, new \DateTimeZone('UTC'), [new PlanChange($id, false, null)]);

        self::assertSame('0 */6 * * *', $this->executed[0]['cronExpression']);
        self::assertSame(0, $this->executed[0]['enabled']);
    }

    public function testStartTimeOnNonDailyScheduleIsRejected(): void
    {
        $id = Uuid::randomHex();
        $this->reader->method('fetchForUpdate')->willReturn($this->row($id, '0 */6 * * *'));

        try {
            $this->writer->apply(self::DATE, new \DateTimeZone('UTC'), [new PlanChange($id, null, '03:15')]);
            self::fail('Expected PlanValidationException.');
        } catch (PlanValidationException) {
            self::assertSame([], $this->executed);
        }
    }

    public function testLockedScheduleAbortsWholeSave(): void
    {
        $first = Uuid::randomHex();
        $second = Uuid::randomHex();
        $this->reader->method('fetchForUpdate')->willReturnMap([
            [$first, $this->row($first, '0 2 * * *')],
            [$second, $this->row($second, '0 3 * * *', 'running')],
        ]);

        $this->expectException(ScheduleLockedException::class);
        $this->writer->apply(self::DATE, new \DateTimeZone('UTC'), [
            new PlanChange($first, false, null),
            new PlanChange($second, false, null),
        ]);
    }

    public function testUnknownRepositoryThrowsNotFound(): void
    {
        $this->reader->method('fetchForUpdate')->willReturn(null);

        $this->expectException(ScheduleNotFoundException::class);
        $this->writer->apply(self::DATE, new \DateTimeZone('UTC'), [new PlanChange(Uuid::randomHex(), true, null)]);
    }
}
