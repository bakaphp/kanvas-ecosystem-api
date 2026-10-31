<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Concerns;

use Baka\Support\Str;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\DataTransferObject\PaymentFailure;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

trait LogsEchoPayFailures
{
    protected function reportUnlessDecline(Throwable $e): void
    {
        if ($e instanceof EchoPayException && ! $e->isGatewayFailure()) {
            return;
        }

        report($e);
    }

    protected function paymentFailure(Throwable $e, ?string $message = null): PaymentFailure
    {
        if ($e instanceof EchoPayException) {
            return $e->toPaymentFailure();
        }

        return new PaymentFailure(code: class_basename($e), message: $message ?? $e->getMessage());
    }

    protected function resolveErrorMessage(Throwable $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $message = json_decode((string) $e->getResponse()->getBody())->message ?? null;

            return is_string($message) ? $message : $e->getMessage();
        }

        return $e->getMessage();
    }

    protected function resolveGatewayMessageBody(Throwable $e): mixed
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            return json_decode((string) $e->getResponse()->getBody())->data ?? [];
        }

        return [];
    }

    protected function logPaymentError(
        Payments $payment,
        Throwable $e,
        array $context,
        ?string $errorMessage = null
    ): void {
        $payment->addLog('payment_error', $context, $this->paymentFailure($e, $errorMessage));
    }

    protected function extractErrorsFromEnrollment(array $responseResult): array
    {
        $data = [
            'message' => '',
            'code' => '',
        ];

        if (isset($responseResult['errorInformation']) && is_array($responseResult['errorInformation'])) {
            $data['message'] = (string) ($responseResult['errorInformation']['message'] ?? '');
            $data['code'] = (string) ($responseResult['errorInformation']['reason'] ?? '');
        }

        return $data;
    }

    protected function logAuthenticationFailure(
        Payments $payment,
        array $enrollmentData,
        ConsumerAuthentication $consumerAuthentication,
        array $errors
    ): void {
        $payment->addLog(
            'payment_authentication_failed',
            [
                'order_id' => $payment->payable->id,
                'amount' => $payment->amount,
                'enrollment_status' => $enrollmentData['status'] ?? null,
                'reason' => $errors['code'],
                'message' => $errors['message'],
                'pares_status' => $consumerAuthentication->paresStatus,
                'eci' => $consumerAuthentication->resolvedEci(),
                'authentication_transaction_id' => $consumerAuthentication->authenticationTransactionId,
                'request_id' => $enrollmentData['id'] ?? null,
            ],
            new PaymentFailure(
                code: Str::trimToNull($errors['code']),
                message: Str::trimToNull($errors['message']),
            ),
        );
    }

    protected function failAuthentication(
        Payments $payment,
        string $stage,
        array $metadata,
        PaymentFailure $failure
    ): void {
        $payment->status = PaymentStatusEnum::FAILED->value;
        $payment->addMetadata($metadata);
        $payment->save();

        $payment->addLog(
            'payment_authentication_error',
            [
                'order_id' => $payment->payable->id,
                'amount' => $payment->amount,
                'stage' => $stage,
                'error' => $failure->message,
            ],
            $failure,
        );

        $this->cancelOrderAfterDecline($payment->payable);
    }

    protected function cancelOrderAfterDecline(Order $order): void
    {
        $order->updateQuietly([
            'status' => OrderStatusEnum::FAILED->value,
            'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
        ]);
    }
}
