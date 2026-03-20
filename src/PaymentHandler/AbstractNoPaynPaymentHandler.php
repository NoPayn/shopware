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

        $this->apiClient->setApiKey($apiKey);

        $amountCents = (int) round((float) $orderData['amount_total'] * 100);
        $currency = $orderData['currency_iso'];

        $failureUrl = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'nopayn_cancelled=1';

        $webhookUrl = $this->router->generate(
            'api.nopayn.webhook',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            $params = [
                'currency' => $currency,
                'amount' => $amountCents,
                'merchant_order_id' => $orderData['order_number'],
                'description' => 'Order ' . $orderData['order_number'],
                'return_url' => $returnUrl,
                'failure_url' => $failureUrl,
                'webhook_url' => $webhookUrl,
                'transactions' => [
                    ['payment_method' => $this->getPaymentMethodIdentifier()],
                ],
                'expiration_period' => 'PT5M',
            ];

            $locale = $this->resolveLocale($orderData['language_id']);
            if ($locale !== null) {
                $params['locale'] = $locale;
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
            $this->logger->error('NoPayn pay() error', [
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
        $this->apiClient->setApiKey($apiKey);

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
            $this->logger->error('NoPayn finalize() verification error', ['error' => $e->getMessage()]);
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

        if (\in_array($status, ['processing', 'new'], true)) {
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
