<?php

declare(strict_types=1);

namespace NoPayn\Payment\Installer;

use NoPayn\Payment\PaymentHandler\ApplePayPaymentHandler;
use NoPayn\Payment\PaymentHandler\CreditCardPaymentHandler;
use NoPayn\Payment\PaymentHandler\GooglePayPaymentHandler;
use NoPayn\Payment\PaymentHandler\VippsMobilePayPaymentHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class PaymentMethodInstaller
{
    private const PAYMENT_METHODS = [
        [
            'handlerIdentifier' => CreditCardPaymentHandler::class,
            'technicalName' => 'nopayn_credit_card',
            'name' => CreditCardPaymentHandler::PAYMENT_METHOD_NAME,
            'description' => CreditCardPaymentHandler::PAYMENT_METHOD_DESCRIPTION,
            'translations' => [
                'en-GB' => [
                    'name' => 'Credit / Debit Card',
                    'description' => 'Pay securely with Visa, Mastercard, Amex and more',
                ],
                'de-DE' => [
                    'name' => 'Kredit- / Debitkarte',
                    'description' => 'Sicher bezahlen mit Visa, Mastercard, Amex und mehr',
                ],
            ],
        ],
        [
            'handlerIdentifier' => ApplePayPaymentHandler::class,
            'technicalName' => 'nopayn_apple_pay',
            'name' => ApplePayPaymentHandler::PAYMENT_METHOD_NAME,
            'description' => ApplePayPaymentHandler::PAYMENT_METHOD_DESCRIPTION,
            'translations' => [
                'en-GB' => [
                    'name' => 'Apple Pay',
                    'description' => 'Pay with Apple Pay',
                ],
                'de-DE' => [
                    'name' => 'Apple Pay',
                    'description' => 'Bezahlen mit Apple Pay',
                ],
            ],
        ],
        [
            'handlerIdentifier' => GooglePayPaymentHandler::class,
            'technicalName' => 'nopayn_google_pay',
            'name' => GooglePayPaymentHandler::PAYMENT_METHOD_NAME,
            'description' => GooglePayPaymentHandler::PAYMENT_METHOD_DESCRIPTION,
            'translations' => [
                'en-GB' => [
                    'name' => 'Google Pay',
                    'description' => 'Pay with Google Pay',
                ],
                'de-DE' => [
                    'name' => 'Google Pay',
                    'description' => 'Bezahlen mit Google Pay',
                ],
            ],
        ],
        [
            'handlerIdentifier' => VippsMobilePayPaymentHandler::class,
            'technicalName' => 'nopayn_vipps_mobilepay',
            'name' => VippsMobilePayPaymentHandler::PAYMENT_METHOD_NAME,
            'description' => VippsMobilePayPaymentHandler::PAYMENT_METHOD_DESCRIPTION,
            'translations' => [
                'en-GB' => [
                    'name' => 'Vipps MobilePay',
                    'description' => 'Pay with Vipps MobilePay',
                ],
                'de-DE' => [
                    'name' => 'Vipps MobilePay',
                    'description' => 'Bezahlen mit Vipps MobilePay',
                ],
            ],
        ],
    ];

    public function __construct(
        private readonly EntityRepository $paymentMethodRepository,
        private readonly PluginIdProvider $pluginIdProvider,
        private readonly string $pluginClass,
    ) {
    }

    public function install(Context $context): void
    {
        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass($this->pluginClass, $context);

        foreach (self::PAYMENT_METHODS as $method) {
            $existingId = $this->getPaymentMethodByHandler($method['handlerIdentifier'], $context);

            $data = [
                'handlerIdentifier' => $method['handlerIdentifier'],
                'technicalName' => $method['technicalName'],
                'name' => $method['name'],
                'description' => $method['description'],
                'pluginId' => $pluginId,
                'afterOrderEnabled' => true,
                'translations' => $method['translations'],
            ];

            if ($existingId !== null) {
                $data['id'] = $existingId;
            }

            $this->paymentMethodRepository->upsert([$data], $context);
        }
    }

    public function activate(Context $context): void
    {
        $this->setActiveState(true, $context);
    }

    public function deactivate(Context $context): void
    {
        $this->setActiveState(false, $context);
    }

    private function setActiveState(bool $active, Context $context): void
    {
        foreach (self::PAYMENT_METHODS as $method) {
            $existing = $this->getPaymentMethodByHandler($method['handlerIdentifier'], $context);
            if ($existing === null) {
                continue;
            }

            $this->paymentMethodRepository->update([
                [
                    'id' => $existing,
                    'active' => $active,
                ],
            ], $context);
        }
    }

    private function getPaymentMethodByHandler(string $handlerIdentifier, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier));
        $criteria->setLimit(1);

        $result = $this->paymentMethodRepository->searchIds($criteria, $context);

        return $result->firstId();
    }
}
