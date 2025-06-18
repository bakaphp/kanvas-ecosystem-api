<?php

namespace Kanvas\Connectors\PasoRapido\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class CreatePasoRapidoOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    private const MAX_RETRIES = 3;
    private const INITIAL_DELAY = 1000; // 1 second in milliseconds
    private const MAX_DELAY = 30000; // 30 seconds in milliseconds

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::PASO_RAPIDO,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                return $this->executeWithRetries($order, $app);
            },
            company: $order->company,
        );
    }

    /**
     * Execute the payment confirmation with retry logic.
     */
    private function executeWithRetries(Model $order, AppInterface $app): array
    {
        if (! isset($order->metadata['data']['paso_rapido_tag'])) {
            return [
                'order' => $order->getId(),
                'status' => 'error',
                'message' => 'Paso Rapido tag not found',
            ];
        }

        if ($order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value) === PaymentStatusEnum::PAID->value) {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Payment already confirmed',
            ];
        }

        $tag = $order->metadata['data']['paso_rapido_tag'];
        $currentRetry = $this->getCurrentRetryCount($order);
        $maxRetries = $this->getMaxRetries($order);

        // Check if we've exceeded max retries
        if ($currentRetry >= $maxRetries) {
            $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::FAILED->value);
            $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode([
                'error' => 'Max retries exceeded',
                'retry_count' => $currentRetry,
                'max_retries' => $maxRetries,
            ]));

            return [
                'order' => $order->getId(),
                'status' => 'error',
                'tag' => $tag,
                'message' => 'Max retries exceeded',
                'retry_count' => $currentRetry,
                'max_retries' => $maxRetries,
                'report' => 'fail',
            ];
        }

        try {
            $pasoRapidoService = new PasoRapidoService($app, $order->company);
            $intentId = $order->get(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
            $bankTransaction = explode(':', $intentId)[1];
            $confirmPaymentResponse = $pasoRapidoService->confirmPayment(new PaymentConfirmData(
                reference: $tag,
                bankTransaction: $bankTransaction,
                amount: $order->getTotalAmount(),
                fiscalCredit: false,
                dni: $order->get(CustomFieldEnum::PASO_RAPIDO_DNI->value) ?? "",
            ));

            if ($confirmPaymentResponse->tag) {
                $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::PAID->value);
                $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode($confirmPaymentResponse->toArray()));
                
                // Clear retry count on success
                $this->clearRetryCount($order);

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'tag' => $tag,
                    'message' => 'Payment confirmed',
                    'retry_count' => $currentRetry,
                ];
            }

            // If no tag returned, treat as failure
            throw new \Exception('No tag returned from payment confirmation');

        } catch (Throwable $e) {
            $isRetryable = $this->isRetryableError($e);
            $newRetryCount = $currentRetry + 1;

            if ($isRetryable && $newRetryCount < $maxRetries) {
                // Increment retry count and schedule retry
                $this->incrementRetryCount($order);
                $delay = $this->calculateDelay($newRetryCount);

                $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode([
                    'error' => $e->getMessage(),
                    'retry_count' => $newRetryCount,
                    'max_retries' => $maxRetries,
                    'next_retry_delay' => $delay,
                    'retryable' => true,
                ]));

                return [
                    'order' => $order->getId(),
                    'status' => 'retry',
                    'tag' => $tag,
                    'message' => 'Payment confirmation failed, will retry',
                    'retry_count' => $newRetryCount,
                    'max_retries' => $maxRetries,
                    'next_retry_delay' => $delay,
                    'error' => $e->getMessage(),
                    'report' => 'retry',
                ];
            } else {
                // Final failure - no more retries
                $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::FAILED->value);
                $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode([
                    'error' => $e->getMessage(),
                    'retry_count' => $newRetryCount,
                    'max_retries' => $maxRetries,
                    'retryable' => false,
                    'trace' => $e->getTraceAsString(),
                ]));

                return [
                    'order' => $order->getId(),
                    'status' => 'error',
                    'tag' => $tag,
                    'message' => $e->getMessage(),
                    'retry_count' => $newRetryCount,
                    'max_retries' => $maxRetries,
                    'report' => 'fail',
                    'trace' => $e->getTraceAsString(),
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
