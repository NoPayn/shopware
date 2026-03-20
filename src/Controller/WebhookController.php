<?php

declare(strict_types=1);

namespace NoPayn\Payment\Controller;

use Doctrine\DBAL\Connection;
use NoPayn\Payment\Service\NoPaynApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController extends StorefrontController
{
    private const FINAL_STATUSES = ['completed', 'cancelled', 'expired'];

    public function __construct(
        private readonly NoPaynApiClient $apiClient,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/nopayn/webhook',
        name: 'frontend.nopayn.webhook',
        methods: ['POST'],
        defaults: ['csrf_protected' => false, 'XmlHttpRequest' => false, 'auth_required' => false],
    )]
    public function webhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (!isset($payload['order_id'])) {
            $this->logger->warning('NoPayn webhook: missing order_id');
            return new JsonResponse(['status' => 'error', 'message' => 'Missing order_id'], 400);
        }

        $nopaynOrderId = $payload['order_id'];

        $nopaynTx = $this->connection->fetchAssociative(
            'SELECT * FROM `nopayn_transactions` WHERE `nopayn_order_id` = :id LIMIT 1',
            ['id' => $nopaynOrderId]
        );

        if (!$nopaynTx) {
            $this->logger->warning('NoPayn webhook: unknown order_id', ['nopaynOrderId' => $nopaynOrderId]);
            return new JsonResponse(['status' => 'ok']);
        }

        if (\in_array($nopaynTx['status'], self::FINAL_STATUSES, true)) {
            return new JsonResponse(['status' => 'ok', 'info' => 'already_final']);
        }

        $apiKey = $this->systemConfigService->getString('NoPaynPayment.config.apiKey');
        if ($apiKey === '') {
            $this->logger->error('NoPayn webhook: API key not configured');
            return new JsonResponse(['status' => 'ok']);
        }

        $this->apiClient->setApiKey($apiKey);

        try {
            $nopaynOrder = $this->apiClient->getOrder($nopaynOrderId);
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn webhook: API error', ['error' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error'], 500);
        }

        $status = $nopaynOrder['status'] ?? 'error';
        $orderTransactionId = Uuid::fromBytesToHex($nopaynTx['order_transaction_id']);
        $orderId = Uuid::fromBytesToHex($nopaynTx['order_id']);
        $context = Context::createDefaultContext();

        $this->connection->executeStatement(
            'UPDATE `nopayn_transactions` SET `status` = :status, `updated_at` = :now WHERE `nopayn_order_id` = :id',
            [
                'status' => $status,
                'now' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                'id' => $nopaynOrderId,
            ]
        );

        try {
            $currentTxState = $this->getTransactionStateName($orderTransactionId);

            if ($status === 'completed') {
                $this->transitionTransactionToPaid($orderTransactionId, $currentTxState, $context);
                $this->transitionOrderTo($orderId, 'process', $context);
            } elseif (\in_array($status, ['cancelled', 'expired', 'error'], true)) {
                if ($currentTxState !== OrderTransactionStates::STATE_CANCELLED
                    && $currentTxState !== OrderTransactionStates::STATE_FAILED) {
                    $this->transactionStateHandler->cancel($orderTransactionId, $context);
                }
                $this->transitionOrderTo($orderId, 'cancel', $context);
            }
        } catch (\Throwable $e) {
            $this->logger->error('NoPayn webhook: state transition error', [
                'error' => $e->getMessage(),
                'orderTransactionId' => $orderTransactionId,
                'status' => $status,
            ]);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function transitionTransactionToPaid(string $txId, string $currentState, Context $context): void
    {
        if ($currentState === OrderTransactionStates::STATE_OPEN) {
            $this->transactionStateHandler->process($txId, $context);
            $this->transactionStateHandler->paid($txId, $context);
        } elseif ($currentState === OrderTransactionStates::STATE_IN_PROGRESS) {
            $this->transactionStateHandler->paid($txId, $context);
        }
    }

    private function transitionOrderTo(string $orderId, string $action, Context $context): void
    {
        try {
            $this->stateMachineRegistry->transition(
                new Transition('order', $orderId, $action, 'stateId'),
                $context
            );
        } catch (\Throwable $e) {
            $this->logger->info('NoPayn: order transition skipped', [
                'orderId' => $orderId,
                'action' => $action,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function getTransactionStateName(string $orderTransactionId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT sms.technical_name
             FROM `order_transaction` ot
             INNER JOIN `state_machine_state` sms ON ot.state_id = sms.id
             WHERE ot.id = :id',
            ['id' => Uuid::fromHexToBytes($orderTransactionId)]
        );
    }
}
