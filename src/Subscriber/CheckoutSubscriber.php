<?php

declare(strict_types=1);

namespace NoPayn\Payment\Subscriber;

use NoPayn\Payment\PaymentHandler\ApplePayPaymentHandler;
use NoPayn\Payment\PaymentHandler\CreditCardPaymentHandler;
use NoPayn\Payment\PaymentHandler\GooglePayPaymentHandler;
use NoPayn\Payment\PaymentHandler\VippsMobilePayPaymentHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutSubscriber implements EventSubscriberInterface
{
    private const METHOD_CONFIG_MAP = [
        CreditCardPaymentHandler::class => CreditCardPaymentHandler::CONFIG_KEY,
        ApplePayPaymentHandler::class => ApplePayPaymentHandler::CONFIG_KEY,
        GooglePayPaymentHandler::class => GooglePayPaymentHandler::CONFIG_KEY,
        VippsMobilePayPaymentHandler::class => VippsMobilePayPaymentHandler::CONFIG_KEY,
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onAccountEditOrderLoaded',
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        $page->setPaymentMethods(
            $this->filterDisabledMethods($page->getPaymentMethods(), $salesChannelId)
        );
    }

    public function onAccountEditOrderLoaded(AccountEditOrderPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        $page->setPaymentMethods(
            $this->filterDisabledMethods($page->getPaymentMethods(), $salesChannelId)
        );
    }

    private function filterDisabledMethods(
        PaymentMethodCollection $methods,
        string $salesChannelId,
    ): PaymentMethodCollection {
        return $methods->filter(function (PaymentMethodEntity $method) use ($salesChannelId): bool {
            $configKey = self::METHOD_CONFIG_MAP[$method->getHandlerIdentifier()] ?? null;

            if ($configKey === null) {
                return true;
            }

            // null (never saved) or true → enabled; only explicit false disables
            return $this->systemConfigService->get($configKey, $salesChannelId) !== false;
        });
    }
}
