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
namespace MuckiSafePlugin\Core;

enum ConfigPath: string
{
    case CONFIG_PATH_ACTIVE = 'MuckiSafePlugin.config.active';
    case CONFIG_PATH_SCHEDULE_BUFFER_MINUTES = 'MuckiSafePlugin.config.scheduleBufferMinutes';
}
