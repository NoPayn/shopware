<?php

declare(strict_types=1);

namespace NoPayn\Payment\Subscriber;

use NoPayn\Payment\PaymentHandler\ApplePayPaymentHandler;
use NoPayn\Payment\PaymentHandler\CreditCardPaymentHandler;
use NoPayn\Payment\PaymentHandler\GooglePayPaymentHandler;
use NoPayn\Payment\PaymentHandler\VippsMobilePayPaymentHandler;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Strips the "NoPayn " prefix from payment method names in the storefront
 * so customers see clean names like "Credit / Debit Card" while the admin
 * still sees "NoPayn Credit / Debit Card".
 */
class CheckoutSubscriber implements EventSubscriberInterface
{
    private const HANDLER_DISPLAY_MAP = [
        CreditCardPaymentHandler::class => CreditCardPaymentHandler::CHECKOUT_DISPLAY_NAME,
        ApplePayPaymentHandler::class => ApplePayPaymentHandler::CHECKOUT_DISPLAY_NAME,
        GooglePayPaymentHandler::class => GooglePayPaymentHandler::CHECKOUT_DISPLAY_NAME,
        VippsMobilePayPaymentHandler::class => VippsMobilePayPaymentHandler::CHECKOUT_DISPLAY_NAME,
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        foreach ($event->getPage()->getPaymentMethods() as $paymentMethod) {
            $handler = $paymentMethod->getHandlerIdentifier();
            if (isset(self::HANDLER_DISPLAY_MAP[$handler])) {
                $translated = $paymentMethod->getTranslated();
                $translated['name'] = self::HANDLER_DISPLAY_MAP[$handler];
                $paymentMethod->setTranslated($translated);
            }
        }
    }
}
