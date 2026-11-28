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
namespace MuckiSafePlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiSafePlugin\Core\ConfigPath;
use MuckiSafePlugin\Services\Settings as PluginSettings;
use MuckiSafePlugin\Services\SettingsInterface;

class SettingsTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $config = $this->createMock(SystemConfigService::class);

        static::assertInstanceOf(SettingsInterface::class, new PluginSettings($config));
    }

    public function testIsEnabledReadsActiveConfigKey(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(static::once())
            ->method('getBool')
            ->with(ConfigPath::CONFIG_PATH_ACTIVE->value)
            ->willReturn(true);

        static::assertTrue((new PluginSettings($config))->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getBool')->willReturn(false);

        static::assertFalse((new PluginSettings($config))->isEnabled());
    }

    public function testScheduleBufferReadsConfigKey(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(static::once())
            ->method('getInt')
            ->with(ConfigPath::CONFIG_PATH_SCHEDULE_BUFFER_MINUTES->value)
            ->willReturn(20);

        static::assertSame(20, (new PluginSettings($config))->getScheduleBufferMinutes());
    }

    public function testScheduleBufferFallsBackToDefaultWhenNotPositive(): void
    {
        foreach ([0, -5] as $value) {
            $config = $this->createMock(SystemConfigService::class);
            $config->method('getInt')->willReturn($value);

            static::assertSame(
                PluginSettings::DEFAULT_SCHEDULE_BUFFER_MINUTES,
                (new PluginSettings($config))->getScheduleBufferMinutes()
            );
            static::assertSame(15, (new PluginSettings($config))->getScheduleBufferMinutes());
        }
    }

    public function testConfigPathUsesPluginNamespace(): void
    {
        foreach (ConfigPath::cases() as $case) {
            static::assertStringStartsWith('MuckiSafePlugin.config.', $case->value);
        }
    }
}
