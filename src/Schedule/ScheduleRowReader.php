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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

class ScheduleRowReader
{
    private const SAMPLE_SIZE = 5;

    public function __construct(protected Connection $connection)
    {
    }

    /**
     * @return list<ScheduleRow> active repositories with a schedule, ordered by name
     */
    public function fetchActive(): array
    {
        $rows = $this->connection->fetchAllAssociative($this->scheduleSql() . ' ORDER BY r.name ASC');

        return $this->hydrate($rows);
    }

    /**
     * Reads one schedule row and locks it (FOR UPDATE) inside the caller's transaction.
     */
    public function fetchForUpdate(string $repositoryId): ?ScheduleRow
    {
        if (!Uuid::isValid($repositoryId)) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            $this->scheduleSql() . ' AND r.id = :id FOR UPDATE',
            ['id' => Uuid::fromHexToBytes($repositoryId)]
        );

        return $this->hydrate($rows)[0] ?? null;
    }

    private function scheduleSql(): string
    {
        return 'SELECT r.id AS repository_id, r.name, s.enabled, s.status, s.cron_expression, s.timezone
                FROM muwa_backup_repository r
                INNER JOIN muwa_backup_schedule s ON s.backup_repository_id = r.id
                WHERE COALESCE(r.active, 0) = 1';
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ScheduleRow>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $durations = $this->fetchDurations(array_column($rows, 'repository_id'));

        $result = [];
        foreach ($rows as $row) {
            $stats = $durations[Uuid::fromBytesToHex($row['repository_id'])] ?? null;
            $result[] = new ScheduleRow(
                Uuid::fromBytesToHex($row['repository_id']),
                (string)$row['name'],
                (bool)$row['enabled'],
                (string)$row['status'],
                (string)$row['cron_expression'],
                (string)$row['timezone'],
                $stats['max'] ?? null,
                $stats['count'] ?? 0
            );
        }

        return $result;
    }

    /**
     * Maximum and count of the most recent backup durations per repository.
     *
     * @param list<string> $binaryIds
     *
     * @return array<string, array{max: int, count: int}> keyed by hex repository id
     */
    private function fetchDurations(array $binaryIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT backup_repository_id, MAX(backup_duration) AS max_duration, COUNT(*) AS sample_count
             FROM (
                 SELECT backup_repository_id, backup_duration,
                        ROW_NUMBER() OVER (
                            PARTITION BY backup_repository_id ORDER BY created_at DESC
                        ) AS rn
                 FROM muwa_backup_repository_stats
                 WHERE backup_duration IS NOT NULL AND backup_repository_id IN (:ids)
             ) ranked
             WHERE rn <= ' . self::SAMPLE_SIZE . '
             GROUP BY backup_repository_id',
            ['ids' => $binaryIds],
            ['ids' => ArrayParameterType::BINARY]
        );

        $durations = [];
        foreach ($rows as $row) {
            $durations[Uuid::fromBytesToHex($row['backup_repository_id'])] = [
                'max' => (int)$row['max_duration'],
                'count' => (int)$row['sample_count'],
            ];
        }

        return $durations;
    }
}
