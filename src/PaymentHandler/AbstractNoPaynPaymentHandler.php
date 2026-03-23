<?php

declare(strict_types=1);

namespace NoPayn\Payment\PaymentHandler;

use Doctrine\DBAL\Connection;
use NoPayn\Payment\Service\NoPaynApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

abstract class AbstractNoPaynPaymentHandler extends AbstractPaymentHandler
{
    private const LOCALE_MAP = [
        'en-GB' => 'en-GB',
        'en-US' => 'en-GB',
        'de-DE' => 'de-DE',
        'de-AT' => 'de-DE',
        'de-CH' => 'de-DE',
        'nl-NL' => 'nl-NL',
        'nl-BE' => 'nl-BE',
        'fr-FR' => 'fr-BE',
        'fr-BE' => 'fr-BE',
        'sv-SE' => 'sv-SE',
        'nb-NO' => 'no-NO',
        'nn-NO' => 'no-NO',
        'da-DK' => 'da-DK',
    ];

    public function __construct(
        protected readonly NoPaynApiClient $apiClient,
        protected readonly OrderTransactionStateHandler $transactionStateHandler,
        protected readonly SystemConfigService $systemConfigService,
        protected readonly Connection $connection,
        protected readonly RouterInterface $router,
        protected readonly StateMachineRegistry $stateMachineRegistry,
        protected readonly LoggerInterface $logger,
    ) {
    }

    abstract protected function getPaymentMethodIdentifier(): string;

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct,
    ): ?RedirectResponse {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $returnUrl = $transaction->getReturnUrl();

        $orderData = $this->loadOrderData($orderTransactionId);
        if ($orderData === null) {
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Could not load order data for transaction.'
            );
        }

        $salesChannelId = $orderData['sales_channel_id'];
        $apiKey = $this->systemConfigService->getString('NoPaynPayment.config.apiKey', $salesChannelId);
        if ($apiKey === '') {
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'NoPayn API key is not configured. Go to Settings > Extensions > NoPayn Payment.'
            );
        }

        $debugLogging = (bool) $this->systemConfigService->get('NoPaynPayment.config.debugLogging', $salesChannelId);
        $this->apiClient->setApiKey($apiKey);
        $this->apiClient->setDebugLogging($debugLogging);

        $amountCents = (int) round((float) $orderData['amount_total'] * 100);
        $currency = $orderData['currency_iso'];

        $failureUrl = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'nopayn_cancelled=1';

        $webhookUrl = $this->router->generate(
            'api.nopayn.webhook',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            $transactionData = ['payment_method' => $this->getPaymentMethodIdentifier()];

            // Manual capture for credit card
            $captureMode = 'automatic';
            if ($this->getPaymentMethodIdentifier() === CreditCardPaymentHandler::PAYMENT_METHOD_IDENTIFIER) {
                $manualCapture = (bool) $this->systemConfigService->get(
                    'NoPaynPayment.config.creditCardManualCapture',
                    $salesChannelId
                );
                if ($manualCapture) {
                    $transactionData['capture_mode'] = 'manual';
                    $captureMode = 'manual';
                }
            }

            $params = [
                'currency' => $currency,
                'amount' => $amountCents,
                'merchant_order_id' => $orderData['order_number'],
                'description' => 'Order ' . $orderData['order_number'],
                'return_url' => $returnUrl,
                'failure_url' => $failureUrl,
                'webhook_url' => $webhookUrl,
                'transactions' => [$transactionData],
                'expiration_period' => 'PT5M',
            ];

            $locale = $this->resolveLocale($orderData['language_id']);
            if ($locale !== null) {
                $params['locale'] = $locale;
            }

            // Build itemized order lines
            $orderLines = $this->buildOrderLines($orderTransactionId, $currency);
            if ($orderLines !== []) {
                $params['order_lines'] = $orderLines;
            }

            if ($debugLogging) {
                $this->logger->debug('NoPayn: creating order', [
                    'orderNumber' => $orderData['order_number'],
                    'amount' => $amountCents,
                    'currency' => $currency,
                    'captureMode' => $captureMode,
                ]);
            }

            $nopaynOrder = $this->apiClient->createOrder($params);

            $this->connection->insert('nopayn_transactions', [
                'id' => Uuid::randomBytes(),
                'order_transaction_id' => Uuid::fromHexToBytes($orderTransactionId),
                'order_id' => $orderData['order_id_bin'],
                'nopayn_order_id' => $nopaynOrder['id'],
                'payment_method' => $this->getPaymentMethodIdentifier(),
                'amount' => $amountCents,
                'currency' => $currency,
                'status' => 'new',
                'capture_mode' => $captureMode,
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s.v'),
            ]);

            $this->transactionStateHandler->process($orderTransactionId, $context);

            $paymentUrl = $nopaynOrder['transactions'][0]['payment_url']
                ?? $nopaynOrder['order_url']
                ?? null;

            if ($paymentUrl === null) {
                throw new \RuntimeException('No payment URL received from NoPayn API');
            }

            return new RedirectResponse($paymentUrl);
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn: pay() error', [
                'error' => $e->getMessage(),
                'orderNumber' => $orderData['order_number'],
            ]);
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Payment could not be processed: ' . $e->getMessage()
            );
        }
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
    ): void {
        $orderTransactionId = $transaction->getOrderTransactionId();

        $orderData = $this->loadOrderData($orderTransactionId);
        $salesChannelId = $orderData['sales_channel_id'] ?? null;

        $apiKey = $this->systemConfigService->getString('NoPaynPayment.config.apiKey', $salesChannelId);
        $debugLogging = (bool) $this->systemConfigService->get('NoPaynPayment.config.debugLogging', $salesChannelId);
        $this->apiClient->setApiKey($apiKey);
        $this->apiClient->setDebugLogging($debugLogging);

        if ($request->query->get('nopayn_cancelled')) {
            $this->updateTransactionStatus($orderTransactionId, 'cancelled');
            if ($orderData !== null) {
                $this->cancelOrder(Uuid::fromBytesToHex($orderData['order_id_bin']), $context);
            }
            throw PaymentException::customerCanceled(
                $orderTransactionId,
                'Payment was cancelled by the customer.'
            );
        }

        $nopaynTx = $this->getTransactionByOrderTransactionId($orderTransactionId);
        if ($nopaynTx === null) {
            throw PaymentException::customerCanceled(
                $orderTransactionId,
                'NoPayn transaction record not found.'
            );
        }

        try {
            $nopaynOrder = $this->apiClient->getOrder($nopaynTx['nopayn_order_id']);
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn: finalize() verification error', ['error' => $e->getMessage()]);
            throw PaymentException::customerCanceled(
                $orderTransactionId,
                'Could not verify payment status: ' . $e->getMessage()
            );
        }

        $status = $nopaynOrder['status'] ?? 'error';
        $this->updateTransactionStatus($orderTransactionId, $status);

        if ($status === 'completed') {
            return;
        }

        if (\in_array($status, ['processing', 'new', 'authorized'], true)) {
            return;
        }

        if ($orderData !== null) {
            $this->cancelOrder(Uuid::fromBytesToHex($orderData['order_id_bin']), $context);
        }
        throw PaymentException::customerCanceled(
            $orderTransactionId,
            'Payment was not completed (status: ' . $status . ').'
        );
    }

    public function getTransactionByOrderTransactionId(string $orderTransactionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `nopayn_transactions` WHERE `order_transaction_id` = :id ORDER BY `created_at` DESC LIMIT 1',
            ['id' => Uuid::fromHexToBytes($orderTransactionId)]
        );

        return $row ?: null;
    }

    public function getTransactionByNopaynOrderId(string $nopaynOrderId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `nopayn_transactions` WHERE `nopayn_order_id` = :id LIMIT 1',
            ['id' => $nopaynOrderId]
        );

        return $row ?: null;
    }

    public function updateTransactionStatus(string $orderTransactionId, string $status): void
    {
        $this->connection->executeStatement(
            'UPDATE `nopayn_transactions` SET `status` = :status, `updated_at` = :now WHERE `order_transaction_id` = :id',
            [
                'status' => $status,
                'now' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                'id' => Uuid::fromHexToBytes($orderTransactionId),
            ]
        );
    }

    private function loadOrderData(string $orderTransactionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                ot.order_id AS order_id_bin,
                o.order_number,
                o.amount_total,
                o.language_id,
                o.sales_channel_id,
                c.iso_code AS currency_iso
             FROM `order_transaction` ot
             INNER JOIN `order` o ON ot.order_id = o.id AND ot.order_version_id = o.version_id
             INNER JOIN `currency` c ON o.currency_id = c.id
             WHERE ot.id = :id',
            ['id' => Uuid::fromHexToBytes($orderTransactionId)]
        );

        if (!$row) {
            return null;
        }

        $row['sales_channel_id'] = Uuid::fromBytesToHex($row['sales_channel_id']);
        $row['language_id'] = Uuid::fromBytesToHex($row['language_id']);

        return $row;
    }

    private function buildOrderLines(string $orderTransactionId, string $currency): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                oli.id AS line_item_id,
                oli.label,
                oli.quantity,
                oli.unit_price,
                oli.total_price,
                oli.type
             FROM `order_transaction` ot
             INNER JOIN `order_line_item` oli ON ot.order_id = oli.order_id AND ot.order_version_id = oli.version_id
             WHERE ot.id = :id',
            ['id' => Uuid::fromHexToBytes($orderTransactionId)]
        );

        $orderLines = [];

        foreach ($rows as $row) {
            $unitPriceCents = (int) round((float) $row['unit_price'] * 100);
            $vatPercentage = $this->getLineItemVatRate($row['line_item_id']);

            $type = 'physical';
            if ($row['type'] === 'product') {
                $type = 'physical';
            } elseif (\in_array($row['type'], ['promotion', 'discount'], true)) {
                $type = 'discount';
            }

            $orderLines[] = [
                'type' => $type,
                'name' => $row['label'],
                'quantity' => (int) $row['quantity'],
                'amount' => $unitPriceCents,
                'currency' => $currency,
                'vat_percentage' => $vatPercentage,
                'merchant_order_line_id' => Uuid::fromBytesToHex($row['line_item_id']),
            ];
        }

        // Add shipping costs
        $shippingCosts = $this->getShippingCosts($orderTransactionId);
        if ($shippingCosts !== null && $shippingCosts['amount'] > 0) {
            $orderLines[] = [
                'type' => 'shipping_fee',
                'name' => 'Shipping',
                'quantity' => 1,
                'amount' => $shippingCosts['amount'],
                'currency' => $currency,
                'vat_percentage' => $shippingCosts['vat_percentage'],
                'merchant_order_line_id' => 'shipping',
            ];
        }

        return $orderLines;
    }

    private function getLineItemVatRate(string $lineItemIdBin): int
    {
        try {
            $taxRate = $this->connection->fetchOne(
                'SELECT olit.tax_rate
                 FROM `order_line_item_tax` olit
                 WHERE olit.order_line_item_id = :id
                 LIMIT 1',
                ['id' => $lineItemIdBin]
            );

            if ($taxRate !== false) {
                return (int) round((float) $taxRate * 100);
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    private function getShippingCosts(string $orderTransactionId): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT o.shipping_total
                 FROM `order_transaction` ot
                 INNER JOIN `order` o ON ot.order_id = o.id AND ot.order_version_id = o.version_id
                 WHERE ot.id = :id',
                ['id' => Uuid::fromHexToBytes($orderTransactionId)]
            );

            if ($row && (float) $row['shipping_total'] > 0) {
                $amountCents = (int) round((float) $row['shipping_total'] * 100);

                return [
                    'amount' => $amountCents,
                    'vat_percentage' => 0,
                ];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function cancelOrder(string $orderId, Context $context): void
    {
        try {
            $this->stateMachineRegistry->transition(
                new Transition('order', $orderId, 'cancel', 'stateId'),
                $context
            );
        } catch (\Throwable $e) {
            $this->logger->info('NoPayn: order cancel transition skipped', [
                'orderId' => $orderId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function resolveLocale(string $languageIdHex): ?string
    {
        try {
            $localeCode = $this->connection->fetchOne(
                'SELECT lo.code FROM `language` la
                 INNER JOIN `locale` lo ON la.locale_id = lo.id
                 WHERE la.id = :id',
                ['id' => Uuid::fromHexToBytes($languageIdHex)]
            );

            if ($localeCode && isset(self::LOCALE_MAP[$localeCode])) {
                return self::LOCALE_MAP[$localeCode];
            }
        } catch (\Throwable) {
        }

        return 'en-GB';
    }
}
