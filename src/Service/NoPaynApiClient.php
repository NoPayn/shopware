<?php

declare(strict_types=1);

namespace NoPayn\Payment\Service;

use Psr\Log\LoggerInterface;

class NoPaynApiClient
{
    private const BASE_URL = 'https://api.nopayn.co.uk';
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;

    private string $apiKey = '';
    private bool $debugLogging = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $nopaynDebugLogger,
        private readonly LoggerInterface $nopaynErrorLogger,
    ) {
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setDebugLogging(bool $enabled): void
    {
        $this->debugLogging = $enabled;
    }

    public function createOrder(array $params): array
    {
        return $this->request('POST', '/v1/orders/', $params);
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/v1/orders/' . $orderId . '/');
    }

    public function createRefund(string $orderId, int $amountCents, string $description = ''): array
    {
        $data = ['amount' => $amountCents];
        if ($description !== '') {
            $data['description'] = $description;
        }

        return $this->request('POST', '/v1/orders/' . $orderId . '/refunds/', $data);
    }

    public function captureTransaction(string $orderId, string $transactionId): array
    {
        return $this->request('POST', '/v1/orders/' . $orderId . '/transactions/' . $transactionId . '/captures/');
    }

    public function voidTransaction(string $orderId, string $transactionId, int $amountInCents, string $description = ''): array
    {
        $data = ['amount' => $amountInCents];
        if ($description !== '') {
            $data['description'] = $description;
        }

        return $this->request('POST', '/v1/orders/' . $orderId . '/transactions/' . $transactionId . '/voids/amount/', $data);
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = self::BASE_URL . $endpoint;

        if ($this->debugLogging) {
            $this->nopaynDebugLogger->debug('NoPayn: API request', [
                'method' => $method,
                'url' => $url,
                'body' => $data ?: null,
            ]);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERPWD => $this->apiKey . ':',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            $this->logger->error('NoPayn: API cURL error', ['error' => $error, 'url' => $url]);
            $this->nopaynErrorLogger->error('NoPayn: API cURL error', ['error' => $error, 'url' => $url]);
            throw new \RuntimeException('NoPayn API connection error: ' . $error);
        }

        $result = json_decode((string) $response, true);

        if ($this->debugLogging) {
            $this->nopaynDebugLogger->debug('NoPayn: API response', [
                'url' => $url,
                'httpCode' => $httpCode,
                'response' => $result,
            ]);
        }

        if ($httpCode >= 400) {
            $msg = $result['message'] ?? $result['detail'] ?? (string) $response;
            $this->logger->error('NoPayn: API HTTP error', [
                'httpCode' => $httpCode,
                'response' => $result,
                'url' => $url,
            ]);
            $this->nopaynErrorLogger->error('NoPayn: API HTTP error', [
                'httpCode' => $httpCode,
                'response' => $result,
                'url' => $url,
            ]);
            throw new \RuntimeException('NoPayn API error (HTTP ' . $httpCode . '): ' . $msg);
        }

        return $result ?? [];
    }
}
