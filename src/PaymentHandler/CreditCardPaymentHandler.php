<?php

declare(strict_types=1);

namespace NoPayn\Payment\PaymentHandler;

class CreditCardPaymentHandler extends AbstractNoPaynPaymentHandler
{
    public const PAYMENT_METHOD_IDENTIFIER = 'credit-card';
    public const PAYMENT_METHOD_NAME = 'NoPayn Credit / Debit Card';
    public const PAYMENT_METHOD_DESCRIPTION = 'Pay securely with Visa, Mastercard, Amex and more';
    public const CHECKOUT_DISPLAY_NAME = 'Credit / Debit Card';

    protected function getPaymentMethodIdentifier(): string
    {
        return self::PAYMENT_METHOD_IDENTIFIER;
    }
}
