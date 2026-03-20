<?php

declare(strict_types=1);

namespace NoPayn\Payment\PaymentHandler;

class GooglePayPaymentHandler extends AbstractNoPaynPaymentHandler
{
    public const PAYMENT_METHOD_IDENTIFIER = 'google-pay';
    public const PAYMENT_METHOD_NAME = 'Google Pay';
    public const PAYMENT_METHOD_DESCRIPTION = 'Pay with Google Pay';
    public const CONFIG_KEY = 'NoPaynPayment.config.enableGooglePay';

    protected function getPaymentMethodIdentifier(): string
    {
        return self::PAYMENT_METHOD_IDENTIFIER;
    }
}
