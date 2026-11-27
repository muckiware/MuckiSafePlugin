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
namespace MuckiSafePlugin\Setup;

use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Setup
{
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    public function install(InstallContext $context): void
    {
        (new CustomFieldInstaller($this->container))->sync($context->getContext());
    }

    public function update(UpdateContext $context): void
    {
        (new CustomFieldInstaller($this->container))->sync($context->getContext());
    }

    public function uninstall(UninstallContext $context): void
    {
        (new CustomFieldInstaller($this->container))->remove($context->getContext());
    }
}
