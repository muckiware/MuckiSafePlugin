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

use MuckiSafePlugin\Schedule\Exception\PlanValidationException;
use MuckiSafePlugin\Schedule\PlanChange;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

class PlanChangeTest extends TestCase
{
    public function testValidChange(): void
    {
        $id = Uuid::randomHex();
        $change = PlanChange::fromArray(['repositoryId' => $id, 'enabled' => true, 'startTime' => '03:15']);

        self::assertSame($id, $change->repositoryId);
        self::assertTrue($change->enabled);
        self::assertSame('03:15', $change->startTime);
    }

    public function testToggleOnlyAndStartTimeOnlyAreValid(): void
    {
        self::assertNull(PlanChange::fromArray(['repositoryId' => Uuid::randomHex(), 'enabled' => false])->startTime);
        self::assertNull(PlanChange::fromArray(['repositoryId' => Uuid::randomHex(), 'startTime' => '00:00'])->enabled);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidChanges(): iterable
    {
        yield 'invalid uuid' => [['repositoryId' => 'nope', 'enabled' => true]];
        yield 'missing uuid' => [['enabled' => true]];
        yield 'short hour' => [['repositoryId' => Uuid::randomHex(), 'startTime' => '3:15']];
        yield 'hour 24' => [['repositoryId' => Uuid::randomHex(), 'startTime' => '24:00']];
        yield 'minute 60' => [['repositoryId' => Uuid::randomHex(), 'startTime' => '03:60']];
        yield 'trailing newline' => [['repositoryId' => Uuid::randomHex(), 'startTime' => "03:15\n"]];
        yield 'non-string time' => [['repositoryId' => Uuid::randomHex(), 'startTime' => 315]];
        yield 'non-bool enabled' => [['repositoryId' => Uuid::randomHex(), 'enabled' => 'yes']];
        yield 'neither field' => [['repositoryId' => Uuid::randomHex()]];
    }

    /**
     * @dataProvider invalidChanges
     *
     * @param array<string, mixed> $data
     */
    public function testInvalidChangeIsRejected(array $data): void
    {
        $this->expectException(PlanValidationException::class);
        PlanChange::fromArray($data);
    }

    public function testListIsParsed(): void
    {
        $list = PlanChange::listFromArray([
            ['repositoryId' => Uuid::randomHex(), 'enabled' => true],
            ['repositoryId' => Uuid::randomHex(), 'startTime' => '01:00'],
        ]);

        self::assertCount(2, $list);
    }

    public function testEmptyListIsRejected(): void
    {
        $this->expectException(PlanValidationException::class);
        PlanChange::listFromArray([]);
    }

    public function testNonListIsRejected(): void
    {
        $this->expectException(PlanValidationException::class);
        PlanChange::listFromArray('x');
    }

    public function testMoreThan100ChangesAreRejected(): void
    {
        $changes = [];
        for ($i = 0; $i < 101; ++$i) {
            $changes[] = ['repositoryId' => Uuid::randomHex(), 'enabled' => true];
        }

        $this->expectException(PlanValidationException::class);
        PlanChange::listFromArray($changes);
    }

    public function testDuplicateRepositoryIdsAreRejected(): void
    {
        $id = Uuid::randomHex();

        $this->expectException(PlanValidationException::class);
        PlanChange::listFromArray([
            ['repositoryId' => $id, 'enabled' => true],
            ['repositoryId' => $id, 'startTime' => '02:00'],
        ]);
    }
}
