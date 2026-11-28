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
namespace MuckiSafePlugin\Controller;

use MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryNotFoundException;
use MuckiSafePlugin\Schedule\BackupPlanBuilder;
use MuckiSafePlugin\Schedule\BackupPlanWriter;
use MuckiSafePlugin\Schedule\Exception\PlanValidationException;
use MuckiSafePlugin\Schedule\Exception\ScheduleLockedException;
use MuckiSafePlugin\Schedule\Exception\ScheduleNotFoundException;
use MuckiSafePlugin\Schedule\PlanChange;
use MuckiSafePlugin\Schedule\ScheduleRowReader;
use MuckiSafePlugin\Services\SettingsInterface;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BackupPlanController extends AbstractController
{
    public function __construct(
        protected ScheduleRowReader $reader,
        protected BackupPlanBuilder $builder,
        protected BackupPlanWriter $writer,
        protected SettingsInterface $settings
    ) {
    }

    #[Route(
        path: '/api/_action/muckisafe/backup-plan',
        name: 'api.action.muckisafe.backup_plan.get',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:read']],
        methods: ['GET']
    )]
    public function plan(Request $request): JsonResponse
    {
        $timezone = $this->parseTimezone($request->query->get('timezone'));
        $date = $request->query->has('date')
            ? $this->parseDate($request->query->get('date'))
            : (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');

        return new JsonResponse($this->builder->build(
            $this->reader->fetchActive(),
            $date,
            $timezone,
            $this->settings->getScheduleBufferMinutes()
        ));
    }

    #[Route(
        path: '/api/_action/muckisafe/backup-plan',
        name: 'api.action.muckisafe.backup_plan.save',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['POST']
    )]
    public function save(RequestDataBag $data): Response
    {
        $date = $this->parseDate($data->get('date'));
        $timezone = $this->parseTimezone($data->get('timezone'));

        try {
            $changes = PlanChange::listFromArray($this->toArray($data->get('changes')));
            $this->writer->apply($date, $timezone, $changes);
        } catch (ScheduleNotFoundException | BackupRepositoryNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (ScheduleLockedException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        } catch (PlanValidationException | \InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    protected function parseTimezone(mixed $value): \DateTimeZone
    {
        $name = ($value === null || $value === '') ? 'UTC' : $value;
        if (!is_string($name) || str_contains($name, "\0")) {
            throw new BadRequestHttpException('Invalid timezone.');
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Exception $exception) {
            throw new BadRequestHttpException('Invalid timezone.', $exception);
        }
    }

    protected function parseDate(mixed $value): string
    {
        if (!is_string($value)) {
            throw new BadRequestHttpException('Invalid date, expected Y-m-d.');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
        if ($parsed === false || $hasErrors || $parsed->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Invalid date, expected Y-m-d.');
        }

        return $value;
    }

    protected function toArray(mixed $value): mixed
    {
        if ($value instanceof DataBag) {
            $value = $value->all();
        }
        if (!is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $item): mixed => $this->toArray($item), $value);
    }
}
