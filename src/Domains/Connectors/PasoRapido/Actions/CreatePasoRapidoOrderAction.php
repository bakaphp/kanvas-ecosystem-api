<?php

namespace Kanvas\Connectors\PasoRapido\Actions;


use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Throwable;

class CreatePasoRapidoOrderAction
{
    private const MAX_RETRIES = 3;
    private const INITIAL_DELAY = 1000;
    private const MAX_DELAY = 30000; 

    public function __construct(
        protected Apps $app,
        protected Order $order,
    ) {
    }

    public function execute(): array 
    {
        if (! isset($this->order->metadata['data']['paso_rapido_tag'])) {
            return [
                'order' => $this->order->getId(),
                'status' => 'error',
                'message' => 'Paso Rapido tag not found',
            ];
        }

        if ($this->order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value) === PaymentStatusEnum::PAID->value) {
            return [
                'status' => 'success',
                'message' => 'Payment already confirmed',
                'data' => [
                    'order' => $this->order->getId(),
                ],
            ];
        }

        $tag = $this->order->metadata['data']['paso_rapido_tag'];
        $currentRetry = $this->getCurrentRetryCount($this->order);
        $maxRetries = $this->getMaxRetries($this->order);

        // Check if we've exceeded max retries
        if ($currentRetry >= $maxRetries) {
            $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::FAILED->value);
            $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode([
                'error' => 'Max retries exceeded',
                'retry_count' => $currentRetry,
                'max_retries' => $maxRetries,
            ]));

            return [
                'status' => 'error',
                'message' => 'Max retries exceeded',
                'data' => [
                    'order' => $this->order->getId(),
                    'tag' => $tag,
                    'retry_count' => $currentRetry,
                    'max_retries' => $maxRetries,
                    'report' => 'fail',
                ],
            ];
        }

        try {
            $pasoRapidoService = new PasoRapidoService($this->app, $this->order->company);
            $intentId = $this->order->get(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
            $bankTransaction = explode(':', $intentId)[1];
            $confirmPaymentResponse = $pasoRapidoService->confirmPayment(new PaymentConfirmData(
                reference: $tag,
                bankTransaction: $bankTransaction,
                amount: $this->order->getTotalAmount(),
                fiscalCredit: false,
                dni: $this->order->get(CustomFieldEnum::PASO_RAPIDO_DNI->value) ?? "",
            ));

            if ($confirmPaymentResponse->tag) {
                $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::PAID->value);
                $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode($confirmPaymentResponse->toArray()));
                $this->order->set(EchoPayCustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);
                // Clear retry count on success
                $this->clearRetryCount($this->order);

                return [
                    'status' => 'success',
                    'message' => 'Payment confirmed',
                    'data' => [
                        'order' => $this->order->getId(),
                        'tag' => $tag,
                        'retry_count' => $currentRetry,
                    ],
                ];
            }

            // If no tag returned, treat as failure
            throw new Exception('No tag returned from payment confirmation');

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->descripcionMensaje;
            } else {
                $errorMessage = $e->getMessage();
            }

            $isRetryable = $this->isRetryableError($e);
            $newRetryCount = $currentRetry + 1;

            if ($isRetryable && $newRetryCount < $maxRetries) {
                // Increment retry count and schedule retry
                $this->incrementRetryCount($this->order);
                $delay = $this->calculateDelay($newRetryCount);

                $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode([
                    'error' => $e->getMessage(),
                    'retry_count' => $newRetryCount,
                    'max_retries' => $maxRetries,
                    'next_retry_delay' => $delay,
                    'retryable' => true,
                ]));

                return [
                    'status' => 'retry',
                    'message' => 'Payment confirmation failed, will retry',
                    'data' => [
                        'order' => $this->order->getId(),
                        'tag' => $tag,
                        'retry_count' => $newRetryCount,
                        'max_retries' => $maxRetries,
                        'next_retry_delay' => $delay,
                        'error' => $e->getMessage(),
                        'report' => 'retry',
                    ],
                ];
            } else {
                // Final failure - no more retries
                $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::FAILED->value);
                $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode([
                    'error' => $e->getMessage(),
                    'retry_count' => $newRetryCount,
                    'max_retries' => $maxRetries,
                    'retryable' => false,
                    'trace' => $e->getTraceAsString(),
                ]));
                $this->order->set(EchoPayCustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 0);

                return [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'data' => [
                        'tag' => $tag,
                        'order' => $this->order->getId(),
                        'retry_count' => $newRetryCount,
                        'max_retries' => $maxRetries,
                        'report' => 'fail',
                        'trace' => $e->getTraceAsString(),
                    ],
                ];
            }
        }
    }

    /**
     * Check if the error is retryable.
     */
    private function isRetryableError(Throwable $e): bool
    {
        $retryableErrors = [
            'timeout',
            'connection',
            'network',
            'temporary',
            'rate limit',
            'server error',
            'service unavailable',
            'bad gateway',
            'gateway timeout',
        ];

        $errorMessage = strtolower($e->getMessage());
        
        foreach ($retryableErrors as $retryableError) {
            if (str_contains($errorMessage, $retryableError)) {
                return true;
            }
        }

        // Check for HTTP status codes if available
        if (method_exists($e, 'getCode')) {
            $code = $e->getCode();
            if (in_array($code, [408, 429, 500, 502, 503, 504])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate delay for next retry using exponential backoff.
     */
    private function calculateDelay(int $retryCount): int
    {
        $delay = self::INITIAL_DELAY * (2 ** ($retryCount - 1));
        
        // Add jitter to prevent thundering herd
        $jitter = rand(0, 1000);
        $delay += $jitter;
        
        return min($delay, self::MAX_DELAY);
    }

    /**
     * Get current retry count for the order.
     */
    private function getCurrentRetryCount(Model $order): int
    {
        $retryField = CustomFieldEnum::PASO_RAPIDO_RETRY_COUNT->value ?? 'paso_rapido_retry_count';
        return (int) ($order->get($retryField) ?? 0);
    }

    /**
     * Get max retries for the order.
     */
    private function getMaxRetries(Model $order): int
    {
        $maxRetriesField = CustomFieldEnum::PASO_RAPIDO_MAX_RETRIES->value ?? 'paso_rapido_max_retries';
        return (int) ($order->get($maxRetriesField) ?? self::MAX_RETRIES);
    }

    /**
     * Increment retry count for the order.
     */
    private function incrementRetryCount(Model $order): void
    {
        $retryField = CustomFieldEnum::PASO_RAPIDO_RETRY_COUNT->value ?? 'paso_rapido_retry_count';
        $currentCount = $this->getCurrentRetryCount($order);
        $order->set($retryField, $currentCount + 1);
    }

    /**
     * Clear retry count for the order.
     */
    private function clearRetryCount(Model $order): void
    {
        $retryField = CustomFieldEnum::PASO_RAPIDO_RETRY_COUNT->value ?? 'paso_rapido_retry_count';
        $order->set($retryField, 0);
    }
}
