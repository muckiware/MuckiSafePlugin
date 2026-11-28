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
namespace MuckiSafePlugin\tests\Controller;

use MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryNotFoundException;
use MuckiSafePlugin\Controller\BackupPlanController;
use MuckiSafePlugin\Schedule\BackupPlanBuilder;
use MuckiSafePlugin\Schedule\BackupPlanWriter;
use MuckiSafePlugin\Schedule\Exception\PlanValidationException;
use MuckiSafePlugin\Schedule\Exception\ScheduleLockedException;
use MuckiSafePlugin\Schedule\Exception\ScheduleNotFoundException;
use MuckiSafePlugin\Schedule\ScheduleRowReader;
use MuckiSafePlugin\Services\SettingsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class BackupPlanControllerTest extends TestCase
{
    private ScheduleRowReader&MockObject $reader;

    private BackupPlanBuilder&MockObject $builder;

    private BackupPlanWriter&MockObject $writer;

    private SettingsInterface&MockObject $settings;

    private BackupPlanController $controller;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ScheduleRowReader::class);
        $this->builder = $this->createMock(BackupPlanBuilder::class);
        $this->writer = $this->createMock(BackupPlanWriter::class);
        $this->settings = $this->createMock(SettingsInterface::class);
        $this->controller = new BackupPlanController($this->reader, $this->builder, $this->writer, $this->settings);
    }

    public function testPlanReturnsBuilderPayloadWithConfiguredBuffer(): void
    {
        $this->reader->method('fetchActive')->willReturn([]);
        $this->settings->method('getScheduleBufferMinutes')->willReturn(20);
        $this->builder->expects($this->once())->method('build')
            ->with([], '2026-11-27', $this->callback(
                static fn (\DateTimeZone $tz): bool => $tz->getName() === 'Europe/Berlin'
            ), 20)
            ->willReturn(['date' => '2026-11-27', 'bufferMinutes' => 20, 'items' => []]);

        $response = $this->controller->plan(new Request(['date' => '2026-11-27', 'timezone' => 'Europe/Berlin']));

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(
            ['date' => '2026-11-27', 'bufferMinutes' => 20, 'items' => []],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testPlanRejectsInvalidTimezone(): void
    {
        $this->builder->expects($this->never())->method('build');
        $this->expectException(BadRequestHttpException::class);

        $this->controller->plan(new Request(['date' => '2026-11-27', 'timezone' => 'Mars/Olympus']));
    }

    /**
     * @dataProvider invalidDates
     */
    public function testPlanRejectsInvalidDate(string $date): void
    {
        $this->builder->expects($this->never())->method('build');
        $this->expectException(BadRequestHttpException::class);

        $this->controller->plan(new Request(['date' => $date]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDates(): array
    {
        return [
            'month 13' => ['2026-13-01'],
            'german format' => ['27.11.2026'],
            'day 30 in february' => ['2026-02-30'],
            'trailing garbage' => ['2026-11-27 10:00'],
            'empty' => [''],
        ];
    }

    public function testSaveReturns204(): void
    {
        $changes = [['repositoryId' => Uuid::randomHex(), 'enabled' => true, 'startTime' => '02:00']];
        $this->writer->expects($this->once())->method('apply')
            ->with('2026-11-27', $this->isInstanceOf(\DateTimeZone::class), $this->countOf(1));

        $response = $this->controller->save(new RequestDataBag([
            'date' => '2026-11-27',
            'timezone' => 'UTC',
            'changes' => $changes,
        ]));

        static::assertSame(204, $response->getStatusCode());
    }

    public function testSaveMapsLockedTo409(): void
    {
        $this->writer->method('apply')->willThrowException(new ScheduleLockedException('locked'));
        $this->expectException(ConflictHttpException::class);

        $this->controller->save($this->validBag());
    }

    public function testSaveMapsNotFoundTo404(): void
    {
        $this->writer->method('apply')->willThrowException(new ScheduleNotFoundException('missing'));
        $this->expectException(NotFoundHttpException::class);

        $this->controller->save($this->validBag());
    }

    public function testSaveMapsFacilityNotFoundTo404(): void
    {
        $this->writer->method('apply')->willThrowException(new BackupRepositoryNotFoundException('gone'));
        $this->expectException(NotFoundHttpException::class);

        $this->controller->save($this->validBag());
    }

    public function testSaveMapsValidationTo400(): void
    {
        $this->writer->method('apply')->willThrowException(new PlanValidationException('bad'));
        $this->expectException(BadRequestHttpException::class);

        $this->controller->save($this->validBag());
    }

    public function testSaveMapsInvalidChangesPayloadTo400(): void
    {
        $this->writer->expects($this->never())->method('apply');
        $this->expectException(BadRequestHttpException::class);

        $this->controller->save(new RequestDataBag(['date' => '2026-11-27', 'changes' => 'nope']));
    }

    public function testSaveRejectsInvalidDate(): void
    {
        $this->writer->expects($this->never())->method('apply');
        $this->expectException(BadRequestHttpException::class);

        $this->controller->save(new RequestDataBag(['date' => '2026-02-30', 'changes' => []]));
    }

    public function testRoutesDeclareAcl(): void
    {
        $expected = [
            'plan' => ['api.action.muckisafe.backup_plan.get', ['muwa_backup_repository:read'], ['GET']],
            'save' => ['api.action.muckisafe.backup_plan.save', ['muwa_backup_repository:update'], ['POST']],
        ];

        foreach ($expected as $method => [$name, $acl, $methods]) {
            $attributes = (new \ReflectionMethod(BackupPlanController::class, $method))
                ->getAttributes(Route::class);
            static::assertCount(1, $attributes);
            $arguments = $attributes[0]->getArguments();
            static::assertSame($name, $arguments['name']);
            static::assertSame('/api/_action/muckisafe/backup-plan', $arguments['path']);
            static::assertSame($methods, $arguments['methods']);
            static::assertSame($acl, $arguments['defaults'][PlatformRequest::ATTRIBUTE_ACL]);
        }
    }

    private function validBag(): RequestDataBag
    {
        return new RequestDataBag([
            'date' => '2026-11-27',
            'timezone' => 'UTC',
            'changes' => [['repositoryId' => Uuid::randomHex(), 'enabled' => true, 'startTime' => '02:00']],
        ]);
    }
}
