<?php

declare(strict_types=1);

namespace NoPayn\Payment\PaymentHandler;

class ApplePayPaymentHandler extends AbstractNoPaynPaymentHandler
{
    public const PAYMENT_METHOD_IDENTIFIER = 'apple-pay';
    public const PAYMENT_METHOD_NAME = 'Apple Pay';
    public const PAYMENT_METHOD_DESCRIPTION = 'Pay with Apple Pay';
    public const CONFIG_KEY = 'NoPaynPayment.config.enableApplePay';

    protected function getPaymentMethodIdentifier(): string
    {
        return self::PAYMENT_METHOD_IDENTIFIER;
    }
}
