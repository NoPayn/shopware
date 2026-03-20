<?php

declare(strict_types=1);

namespace NoPayn\Payment\PaymentHandler;

class VippsMobilePayPaymentHandler extends AbstractNoPaynPaymentHandler
{
    public const PAYMENT_METHOD_IDENTIFIER = 'vipps-mobilepay';
    public const PAYMENT_METHOD_NAME = 'Vipps MobilePay';
    public const PAYMENT_METHOD_DESCRIPTION = 'Pay with Vipps MobilePay';
    public const CONFIG_KEY = 'NoPaynPayment.config.enableVippsMobilePay';

    protected function getPaymentMethodIdentifier(): string
    {
        return self::PAYMENT_METHOD_IDENTIFIER;
    }
}
