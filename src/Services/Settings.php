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
namespace MuckiSafePlugin\Services;

use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiSafePlugin\Core\ConfigPath;

class Settings implements SettingsInterface
{
    public const DEFAULT_SCHEDULE_BUFFER_MINUTES = 15;

    public function __construct(
        protected SystemConfigService $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->getBool(ConfigPath::CONFIG_PATH_ACTIVE->value);
    }

    public function getScheduleBufferMinutes(): int
    {
        $minutes = $this->config->getInt(ConfigPath::CONFIG_PATH_SCHEDULE_BUFFER_MINUTES->value);

        return $minutes > 0 ? $minutes : self::DEFAULT_SCHEDULE_BUFFER_MINUTES;
    }
}
