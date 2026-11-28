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
namespace MuckiSafePlugin\tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiSafePlugin\Schedule\ScheduleRowReader;

class ScheduleRowReaderTest extends TestCase
{
    use IntegrationTestBehaviour;

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function reader(): ScheduleRowReader
    {
        return new ScheduleRowReader($this->connection());
    }

    private function insertRepository(string $name, bool $active): string
    {
        $id = Uuid::randomHex();
        $this->connection()->insert('muwa_backup_repository', [
            'id' => Uuid::fromHexToBytes($id),
            'active' => $active ? 1 : 0,
            'name' => $name,
            'type' => 'local',
            'repository_path' => '/tmp/' . $id,
            'repository_password' => 'secret',
            'pre_backup_command_arguments' => '',
            'post_backup_command_arguments' => '',
            'created_at' => '2026-11-01 00:00:00.000',
        ]);

        return $id;
    }

    private function insertSchedule(string $repositoryId): void
    {
        $this->connection()->insert('muwa_backup_schedule', [
            'id' => Uuid::randomBytes(),
            'backup_repository_id' => Uuid::fromHexToBytes($repositoryId),
            'enabled' => 1,
            'cron_expression' => '0 2 * * *',
            'timezone' => 'Europe/Berlin',
            'status' => 'scheduled',
            'created_at' => '2026-11-01 00:00:00.000',
        ]);
    }

    private function insertStat(string $repositoryId, int $duration, string $createdAt): void
    {
        $this->connection()->insert('muwa_backup_repository_stats', [
            'id' => Uuid::randomBytes(),
            'backup_repository_id' => Uuid::fromHexToBytes($repositoryId),
            'backup_duration' => $duration,
            'created_at' => $createdAt,
        ]);
    }

    public function testFetchActiveReturnsMaxOfLastFiveDurations(): void
    {
        $active = $this->insertRepository('Active repo', true);
        $this->insertSchedule($active);
        // The oldest sample (900) must fall out of the five most recent ones.
        foreach ([900, 100, 200, 300, 400, 500] as $i => $duration) {
            $this->insertStat($active, $duration, sprintf('2026-11-%02d 01:00:00.000', $i + 1));
        }

        $inactive = $this->insertRepository('Inactive repo', false);
        $this->insertSchedule($inactive);

        $rows = $this->reader()->fetchActive();

        static::assertCount(1, $rows);
        static::assertSame($active, $rows[0]->repositoryId);
        static::assertSame('Active repo', $rows[0]->name);
        static::assertTrue($rows[0]->enabled);
        static::assertSame('scheduled', $rows[0]->status);
        static::assertSame('0 2 * * *', $rows[0]->cronExpression);
        static::assertSame('Europe/Berlin', $rows[0]->timezone);
        static::assertSame(500, $rows[0]->maxDurationSeconds);
        static::assertSame(5, $rows[0]->durationSampleCount);
    }

    public function testFetchActiveWithoutStatsHasNoDuration(): void
    {
        $active = $this->insertRepository('Active repo', true);
        $this->insertSchedule($active);

        $rows = $this->reader()->fetchActive();

        static::assertCount(1, $rows);
        static::assertNull($rows[0]->maxDurationSeconds);
        static::assertSame(0, $rows[0]->durationSampleCount);
    }

    public function testFetchForUpdateReturnsNullForInactiveRepository(): void
    {
        $inactive = $this->insertRepository('Inactive repo', false);
        $this->insertSchedule($inactive);

        static::assertNull($this->reader()->fetchForUpdate($inactive));
        static::assertNull($this->reader()->fetchForUpdate(Uuid::randomHex()));
    }

    public function testFetchForUpdateReturnsActiveRow(): void
    {
        $active = $this->insertRepository('Active repo', true);
        $this->insertSchedule($active);
        $this->insertStat($active, 120, '2026-11-01 01:00:00.000');

        $row = $this->reader()->fetchForUpdate($active);

        static::assertNotNull($row);
        static::assertSame($active, $row->repositoryId);
        static::assertSame(120, $row->maxDurationSeconds);
        static::assertSame(1, $row->durationSampleCount);
    }
}
