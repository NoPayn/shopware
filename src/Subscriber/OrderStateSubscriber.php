<?php

declare(strict_types=1);

namespace NoPayn\Payment\Subscriber;

use Doctrine\DBAL\Connection;
use NoPayn\Payment\Service\NoPaynApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NoPaynApiClient $apiClient,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $nopaynDebugLogger,
        private readonly LoggerInterface $nopaynErrorLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order.state.completed' => 'onOrderCompleted',
            'state_enter.order_delivery.state.shipped' => 'onOrderDeliveryShipped',
            'state_enter.order.state.cancelled' => 'onOrderCancelled',
        ];
    }

    public function onOrderCompleted(OrderStateMachineStateChangeEvent $event): void
    {
        $this->captureManualTransaction($event);
    }

    public function onOrderDeliveryShipped(OrderStateMachineStateChangeEvent $event): void
    {
        $this->captureManualTransaction($event);
    }

    public function onOrderCancelled(OrderStateMachineStateChangeEvent $event): void
    {
        $this->voidManualTransaction($event);
    }

    private function captureManualTransaction(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();
        $orderId = $order->getId();

        $nopaynTx = $this->connection->fetchAssociative(
            'SELECT * FROM `nopayn_transactions` WHERE `order_id` = :id AND `capture_mode` = :captureMode ORDER BY `created_at` DESC LIMIT 1',
            [
                'id' => Uuid::fromHexToBytes($orderId),
                'captureMode' => 'manual',
            ]
        );

        if (!$nopaynTx) {
            return;
        }

        // Only capture if the status indicates the payment was authorized but not yet captured
        if (!\in_array($nopaynTx['status'], ['authorized', 'processing', 'completed'], true)) {
            return;
        }

        // Skip if already completed (already captured)
        if ($nopaynTx['status'] === 'completed') {
            return;
        }

        $apiKey = $this->systemConfigService->getString('NoPaynPayment.config.apiKey');
        $debugLogging = (bool) $this->systemConfigService->get('NoPaynPayment.config.debugLogging');

        if ($apiKey === '') {
            $this->logger->error('NoPayn: cannot capture, API key not configured');
            $this->nopaynErrorLogger->error('NoPayn: cannot capture, API key not configured');
            return;
        }

        $this->apiClient->setApiKey($apiKey);
        $this->apiClient->setDebugLogging($debugLogging);

        try {
            $nopaynOrder = $this->apiClient->getOrder($nopaynTx['nopayn_order_id']);
            $transactions = $nopaynOrder['transactions'] ?? [];

            if ($transactions === []) {
                $this->logger->warning('NoPayn: no transactions found for capture', [
                    'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
                ]);
                return;
            }

            $transactionId = $transactions[0]['id'] ?? null;
            if ($transactionId === null) {
                return;
            }

            if ($debugLogging) {
                $this->nopaynDebugLogger->debug('NoPayn: capturing transaction on order state change', [
                    'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
                    'transactionId' => $transactionId,
                    'orderId' => $orderId,
                ]);
            }

            $this->apiClient->captureTransaction($nopaynTx['nopayn_order_id'], $transactionId);

            $this->connection->executeStatement(
                'UPDATE `nopayn_transactions` SET `status` = :status, `updated_at` = :now WHERE `nopayn_order_id` = :id',
                [
                    'status' => 'completed',
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                    'id' => $nopaynTx['nopayn_order_id'],
                ]
            );

            $this->logger->info('NoPayn: transaction captured successfully', [
                'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
                'transactionId' => $transactionId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn: capture failed on order state change', [
                'error' => $e->getMessage(),
                'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
            ]);
            $this->nopaynErrorLogger->error('NoPayn: capture failed on order state change', [
                'error' => $e->getMessage(),
                'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
            ]);
        }
    }

    private function voidManualTransaction(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();
        $orderId = $order->getId();

        $nopaynTx = $this->connection->fetchAssociative(
            'SELECT * FROM `nopayn_transactions` WHERE `order_id` = :id AND `capture_mode` = :captureMode ORDER BY `created_at` DESC LIMIT 1',
            [
                'id' => Uuid::fromHexToBytes($orderId),
                'captureMode' => 'manual',
            ]
        );

        if (!$nopaynTx) {
            return;
        }

        // Only void if authorized (not yet captured)
        if (!\in_array($nopaynTx['status'], ['authorized', 'processing'], true)) {
            return;
        }

        $apiKey = $this->systemConfigService->getString('NoPaynPayment.config.apiKey');
        $debugLogging = (bool) $this->systemConfigService->get('NoPaynPayment.config.debugLogging');

        if ($apiKey === '') {
            $this->logger->error('NoPayn: cannot void, API key not configured');
            $this->nopaynErrorLogger->error('NoPayn: cannot void, API key not configured');
            return;
        }

        $this->apiClient->setApiKey($apiKey);
        $this->apiClient->setDebugLogging($debugLogging);

        try {
            $nopaynOrder = $this->apiClient->getOrder($nopaynTx['nopayn_order_id']);
            $transactions = $nopaynOrder['transactions'] ?? [];

            if ($transactions === []) {
                return;
            }

            $transactionId = $transactions[0]['id'] ?? null;
            if ($transactionId === null) {
                return;
            }

            $amountCents = (int) $nopaynTx['amount'];

            if ($debugLogging) {
                $this->nopaynDebugLogger->debug('NoPayn: voiding transaction on order cancel', [
                    'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
                    'transactionId' => $transactionId,
                    'amount' => $amountCents,
                    'orderId' => $orderId,
                ]);
            }

            $this->apiClient->voidTransaction(
                $nopaynTx['nopayn_order_id'],
                $transactionId,
                $amountCents,
                'Order cancelled'
            );

            $this->connection->executeStatement(
                'UPDATE `nopayn_transactions` SET `status` = :status, `updated_at` = :now WHERE `nopayn_order_id` = :id',
                [
                    'status' => 'cancelled',
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                    'id' => $nopaynTx['nopayn_order_id'],
                ]
            );

            $this->logger->info('NoPayn: transaction voided successfully', [
                'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
                'transactionId' => $transactionId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn: void failed on order cancel', [
                'error' => $e->getMessage(),
                'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
            ]);
            $this->nopaynErrorLogger->error('NoPayn: void failed on order cancel', [
                'error' => $e->getMessage(),
                'nopaynOrderId' => $nopaynTx['nopayn_order_id'],
            ]);
        }
    }
}
