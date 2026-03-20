<?php

declare(strict_types=1);

namespace NoPayn\Payment\PaymentHandler;

class GooglePayPaymentHandler extends AbstractNoPaynPaymentHandler
{
    public const PAYMENT_METHOD_IDENTIFIER = 'google-pay';
    public const PAYMENT_METHOD_NAME = 'NoPayn Google Pay';
    public const PAYMENT_METHOD_DESCRIPTION = 'Pay with Google Pay';
    public const CHECKOUT_DISPLAY_NAME = 'Google Pay';

    protected function getPaymentMethodIdentifier(): string
    {
        return self::PAYMENT_METHOD_IDENTIFIER;
    }
}
