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

use MuckiSafePlugin\Schedule\Exception\PlanValidationException;
use Shopware\Core\Framework\Uuid\Uuid;

class PlanChange
{
    public const MAX_CHANGES = 100;

    public function __construct(
        public readonly string $repositoryId,
        public readonly ?bool $enabled,
        public readonly ?string $startTime
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws PlanValidationException
     */
    public static function fromArray(array $data): self
    {
        $repositoryId = $data['repositoryId'] ?? null;
        if (!is_string($repositoryId) || !Uuid::isValid($repositoryId)) {
            throw new PlanValidationException('Invalid repositoryId.');
        }

        $enabled = $data['enabled'] ?? null;
        if ($enabled !== null && !is_bool($enabled)) {
            throw new PlanValidationException('"enabled" must be a boolean.');
        }

        $startTime = $data['startTime'] ?? null;
        if ($startTime !== null
            && (!is_string($startTime) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime) !== 1)
        ) {
            throw new PlanValidationException('"startTime" must be formatted as HH:MM.');
        }

        if ($enabled === null && $startTime === null) {
            throw new PlanValidationException('A change needs "enabled" or "startTime".');
        }

        return new self($repositoryId, $enabled, $startTime);
    }

    /**
     * @return list<PlanChange>
     *
     * @throws PlanValidationException
     */
    public static function listFromArray(mixed $changes): array
    {
        if (!is_array($changes) || $changes === []) {
            throw new PlanValidationException('"changes" must be a non-empty list.');
        }
        if (count($changes) > self::MAX_CHANGES) {
            throw new PlanValidationException(sprintf('At most %d changes are allowed.', self::MAX_CHANGES));
        }

        $result = [];
        $seen = [];
        foreach ($changes as $data) {
            if (!is_array($data)) {
                throw new PlanValidationException('Each change must be an object.');
            }
            $change = self::fromArray($data);
            if (isset($seen[$change->repositoryId])) {
                throw new PlanValidationException('Duplicate repositoryId in changes.');
            }
            $seen[$change->repositoryId] = true;
            $result[] = $change;
        }

        return $result;
    }
}
