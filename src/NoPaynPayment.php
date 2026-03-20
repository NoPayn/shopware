<?php

declare(strict_types=1);

namespace NoPayn\Payment;

use NoPayn\Payment\Installer\PaymentMethodInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class NoPaynPayment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->getInstaller()->install($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->getInstaller()->deactivate($uninstallContext->getContext());

        if (!$uninstallContext->keepUserData()) {
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            $connection->executeStatement('DROP TABLE IF EXISTS `nopayn_refunds`');
            $connection->executeStatement('DROP TABLE IF EXISTS `nopayn_transactions`');
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->getInstaller()->activate($activateContext->getContext());
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->getInstaller()->deactivate($deactivateContext->getContext());
    }

    private function getInstaller(): PaymentMethodInstaller
    {
        return new PaymentMethodInstaller(
            $this->container->get('payment_method.repository'),
            $this->container->get('Shopware\Core\Framework\Plugin\Util\PluginIdProvider'),
            static::class
        );
    }
}
