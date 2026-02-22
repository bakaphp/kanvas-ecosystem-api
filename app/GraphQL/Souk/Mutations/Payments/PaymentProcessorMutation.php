<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;

class PaymentProcessorMutation
{
    public function processPayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $paymentId = (int) $request['paymentId'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'id' => $paymentId,
        ])->first();

        if (! $payment) {
            return [
                'status' => 'error',
                'message' => 'Payment not found',
            ];
        }

        if ($payment->status === PaymentStatusEnum::PAID->value) {
            return [
                'status' => 'error',
                'message' => 'Payment is already paid',
            ];
        }

        $order = $payment->order;

        if (! $order) {
            return [
                'status' => 'error',
                'message' => 'Order not found for this payment',
            ];
        }

        $processorName = $payment->paymentMethod?->processor;

        try {
            $payment->update(['status' => PaymentStatusEnum::PROCESSING->value]);

            $context = [];
            if (isset($payment->metadata['use_hold'])) {
                $context['use_hold'] = (bool) $payment->metadata['use_hold'];
            }

            $processor = ProcessorFactory::make($processorName, $app, $payment->company);
            $result = $processor->authorize($payment, $order, $context);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'payment' => $payment->fresh(),
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function capturePayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $paymentId = (int) $request['paymentId'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'id' => $paymentId,
        ])->first();

        if (! $payment) {
            return [
                'status' => 'error',
                'message' => 'Payment not found',
            ];
        }

        if ($payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return [
                'status' => 'error',
                'message' => 'Payment is not in authorized status. Current status: ' . $payment->status,
            ];
        }

        $order = $payment->order;

        if (! $order) {
            return [
                'status' => 'error',
                'message' => 'Order not found for this payment',
            ];
        }

        $processorName = $payment->paymentMethod?->processor ?? $payment->processor;

        try {
            $amount = isset($request['amount']) ? (float) $request['amount'] : null;

            $processor = ProcessorFactory::make($processorName, $app, $payment->company);
            $result = $processor->capture($payment, $order, $amount);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'payment' => $payment->fresh(),
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function refundPayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $paymentId = (int) $request['paymentId'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'id' => $paymentId,
        ])->first();

        if (! $payment) {
            return [
                'status' => 'error',
                'message' => 'Payment not found',
            ];
        }

        if (! in_array($payment->status, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::AUTHORIZED->value])) {
            return [
                'status' => 'error',
                'message' => 'Payment cannot be refunded. Current status: ' . $payment->status,
            ];
        }

        $order = $payment->order;

        if (! $order) {
            return [
                'status' => 'error',
                'message' => 'Order not found for this payment',
            ];
        }

        $processorName = $payment->paymentMethod?->processor;

        try {
            $amount = isset($request['amount']) ? (float) $request['amount'] : null;

            $processor = ProcessorFactory::make($processorName, $app, $payment->company);
            $result = $processor->refund($payment, $order, $amount);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'payment' => $payment->fresh(),
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function verifyPayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);

        if (isset($request['paymentId'])) {
            $payment = Payments::where([
                'apps_id' => $app->getId(),
                'id' => (int) $request['paymentId'],
            ])->first();

            if (! $payment) {
                return ['status' => 'error', 'message' => 'Payment not found'];
            }

            $order = $payment->order;
        } else {
            $order = Order::where([
                'apps_id' => $app->getId(),
                'id' => (int) $request['orderId'],
            ])->first();

            if (! $order) {
                return ['status' => 'error', 'message' => 'Order not found'];
            }

            $payment = Payments::getLatestForEntity($order, [PaymentStatusEnum::PAID->value]);

            if (! $payment) {
                return ['status' => 'error', 'message' => 'No payment found for this order'];
            }
        }

        if (! $order) {
            return ['status' => 'error', 'message' => 'Order not found for this payment'];
        }

        $processorName = $payment->paymentMethod?->processor ?? $payment->processor;

        try {
            $processor = ProcessorFactory::make($processorName, $app, $payment->company);
            $result = $processor->verify($payment, $order);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'iso_code' => $result->isoCode,
                'transaction_id' => $result->transactionId,
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
