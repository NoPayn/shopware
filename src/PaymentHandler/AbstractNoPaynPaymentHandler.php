<?php

declare(strict_types=1);

namespace NoPayn\Payment\PaymentHandler;

use Doctrine\DBAL\Connection;
use NoPayn\Payment\Service\NoPaynApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

abstract class AbstractNoPaynPaymentHandler implements AsynchronousPaymentHandlerInterface
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

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
    ): RedirectResponse {
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $apiKey = $this->systemConfigService->getString('NoPaynPayment.config.apiKey', $salesChannelId);
        if ($apiKey === '') {
            throw new AsyncPaymentProcessException(
                $orderTransaction->getId(),
                'NoPayn API key is not configured. Go to Settings > Extensions > NoPayn Payment.'
            );
        }

        $this->apiClient->setApiKey($apiKey);

        $amountCents = (int) round($order->getAmountTotal() * 100);
        $currency = $salesChannelContext->getCurrency()->getIsoCode();

        $returnUrl = $transaction->getReturnUrl();
        $failureUrl = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'nopayn_cancelled=1';

        $webhookUrl = $this->router->generate(
            'frontend.nopayn.webhook',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            $params = [
                'currency' => $currency,
                'amount' => $amountCents,
                'merchant_order_id' => $order->getOrderNumber(),
                'description' => 'Order ' . $order->getOrderNumber(),
                'return_url' => $returnUrl,
                'failure_url' => $failureUrl,
                'webhook_url' => $webhookUrl,
                'payment_methods' => [$this->getPaymentMethodIdentifier()],
                'expiration_period' => 'PT5M',
            ];

            $locale = $this->resolveLocale($salesChannelContext);
            if ($locale !== null) {
                $params['locale'] = $locale;
            }

            $nopaynOrder = $this->apiClient->createOrder($params);

            $this->connection->insert('nopayn_transactions', [
                'id' => Uuid::randomBytes(),
                'order_transaction_id' => Uuid::fromHexToBytes($orderTransaction->getId()),
                'order_id' => Uuid::fromHexToBytes($order->getId()),
                'nopayn_order_id' => $nopaynOrder['id'],
                'payment_method' => $this->getPaymentMethodIdentifier(),
                'amount' => $amountCents,
                'currency' => $currency,
                'status' => 'new',
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s.v'),
            ]);

            $this->transactionStateHandler->process(
                $orderTransaction->getId(),
                $salesChannelContext->getContext()
            );

            $paymentUrl = $nopaynOrder['transactions'][0]['payment_url']
                ?? $nopaynOrder['order_url']
                ?? null;

            if ($paymentUrl === null) {
                throw new \RuntimeException('No payment URL received from NoPayn API');
            }

            return new RedirectResponse($paymentUrl);
        } catch (AsyncPaymentProcessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn pay() error', [
                'error' => $e->getMessage(),
                'orderNumber' => $order->getOrderNumber(),
            ]);
            throw new AsyncPaymentProcessException(
                $orderTransaction->getId(),
                'Payment could not be processed: ' . $e->getMessage()
            );
        }
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext,
    ): void {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $apiKey = $this->systemConfigService->getString('NoPaynPayment.config.apiKey', $salesChannelId);
        $this->apiClient->setApiKey($apiKey);

        if ($request->query->get('nopayn_cancelled')) {
            $this->updateTransactionStatus($orderTransactionId, 'cancelled');
            $this->cancelOrder($transaction->getOrder()->getId(), $salesChannelContext->getContext());
            throw new CustomerCanceledAsyncPaymentException(
                $orderTransactionId,
                'Payment was cancelled by the customer.'
            );
        }

        $nopaynTx = $this->getTransactionByOrderTransactionId($orderTransactionId);
        if ($nopaynTx === null) {
            throw new CustomerCanceledAsyncPaymentException(
                $orderTransactionId,
                'NoPayn transaction record not found.'
            );
        }

        try {
            $nopaynOrder = $this->apiClient->getOrder($nopaynTx['nopayn_order_id']);
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn finalize() verification error', ['error' => $e->getMessage()]);
            throw new CustomerCanceledAsyncPaymentException(
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
            // Still processing – webhook will finalize; treat as success for now
            return;
        }

        $this->cancelOrder($transaction->getOrder()->getId(), $salesChannelContext->getContext());
        throw new CustomerCanceledAsyncPaymentException(
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

    private function cancelOrder(string $orderId, \Shopware\Core\Framework\Context $context): void
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

    private function resolveLocale(SalesChannelContext $context): ?string
    {
        try {
            $languageId = $context->getContext()->getLanguageId();
            $localeCode = $this->connection->fetchOne(
                'SELECT lo.code FROM `language` la
                 INNER JOIN `locale` lo ON la.locale_id = lo.id
                 WHERE la.id = :id',
                ['id' => Uuid::fromHexToBytes($languageId)]
            );

            if ($localeCode && isset(self::LOCALE_MAP[$localeCode])) {
                return self::LOCALE_MAP[$localeCode];
            }
        } catch (\Throwable) {
            // Ignore – fallback to en-GB
        }

        return 'en-GB';
    }
}
