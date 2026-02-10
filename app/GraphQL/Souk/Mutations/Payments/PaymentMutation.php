<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\Movipass\Actions\CapturePaymentAction;
use Kanvas\Connectors\Movipass\Actions\ProcessPaymentAction;
use Kanvas\Connectors\Movipass\Actions\ValidatePaymentAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Actions\MakePaymentIntentAction;
use Kanvas\Souk\Payments\Enums\PaymentMethodTypesEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;

class PaymentMutation
{
    public function makePaymentIntent(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $paymentId = (int) $request['paymentID'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'id' => $paymentId,
        ])->first();

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $paymentIntent = new MakePaymentIntentAction($payment);

        return [
            'paymentIntent' => $paymentIntent->execute(),
            'message' => 'message',
        ];
    }

    public function makePaymentIntentFromOrder(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderID'];
        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        $payment = Payments::getLatestForEntity($order);

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $paymentIntent = new MakePaymentIntentAction($payment);

        return [
            'paymentIntent' => $paymentIntent->execute(),
            'message' => 'message',
        ];
    }

    public function addPaymentToOrder(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderID'];
        $user = auth()->user();

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        if (! $order) {
            return [
                'status' => 'error',
                'message' => 'Order not found',
            ];
        }

        if ($order->isPaid()) {
            return [
                'status' => 'error',
                'message' => 'Order is already paid',
            ];
        }

        if ($order->hasAuthorizedPayment()) {
            return [
                'status' => 'error',
                'message' => 'Order already has an authorized payment',
            ];
        }

        if ($order->hasProcessingPayment()) {
            return [
                'status' => 'error',
                'message' => 'Order has a payment currently being processed',
            ];
        }

        $formData = $request['input'];
        $paymentMethodType = $formData['payment_method'] ?? null;
        $paymentMethodId = $formData['payment_methods_id'] ?? $order->metadata['data']['payment_methods_id'] ?? null;

        $isDirectPaymentMethod = in_array($paymentMethodType, [
            PaymentMethodTypesEnum::CASH->value,
            PaymentMethodTypesEnum::BANK_TRANSFER->value,
        ]);

        if (! $paymentMethodId && ! $isDirectPaymentMethod) {
            return [
                'status' => 'error',
                'message' => 'Payment method not found',
            ];
        }

        try {
            $formData['amount'] = $formData['amount'] ?? $order->getTotalAmount();
            $formData['payment_method_type'] = $paymentMethodType;

            if ($paymentMethodType === PaymentMethodTypesEnum::CASH->value) {
                $formData['status'] = PaymentStatusEnum::PAID->value;
            }

            $payment = new CreatePaymentAction($order, $user)->execute($formData);
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'order' => $order,
            ];
        }

        return [
            'status' => 'success',
            'payment' => $payment,
            'order' => $order,
            'message' => 'Payment added to order',
        ];
    }

    public function initiatePayerAuthentication(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderId'];
        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        if ($order->isPaid()) {
            return [
                'status' => 'error',
                'message' => 'Order is already paid',
                'data' => [],
            ];
        }

        $payment = Payments::getLatestForEntity($order);

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        if ($payment->status === PaymentStatusEnum::PAID) {
            return [
                'status' => 'error',
                'message' => 'Payment is already paid',
            ];
        }

        if ($payment->status === PaymentStatusEnum::WAITING_DEVICE_DATA) {
            return [
                'status' => 'error',
                'message' => 'Payment is already waiting for device data',
            ];
        }

        try {
            $payment->update(['status' => PaymentStatusEnum::PROCESSING->value]);

            $paymentProcessor = new PortalPaymentProcessor(
                $app,
                $payment->company,
                []
            );

            $session = $paymentProcessor->startPaymentIntent($payment);

            $consumerAuthenticationInformation = $session['consumerAuthenticationInformation'];

            $payment->order->set('auth_session_id', $consumerAuthenticationInformation['referenceId']);
            $payment->order->set('device_data_url', $consumerAuthenticationInformation['deviceDataCollectionUrl']);
            $payment->order->set('access_token', $consumerAuthenticationInformation['accessToken']);
            $payment->order->set('payment_status', 'waiting_device_data');

            $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA->value;
            $payment->save();

            return [
                'message' => 'Waiting for device data',
                'status' => 'waiting_device_data',
                'data' => [
                    'deviceDataUrl' => $consumerAuthenticationInformation['deviceDataCollectionUrl'],
                    'accessToken' => $consumerAuthenticationInformation['accessToken'],
                    'referenceId' => $consumerAuthenticationInformation['referenceId'],
                ],
            ];
        } catch (EchoPayException $e) {
            // Save error body to payment metadata
            $currentMetadata = $payment->metadata ?? [];
            $currentMetadata['echopay_error'] = $e->getErrorBody();
            $currentMetadata['echopay_error_message'] = $e->getMessage();
            $currentMetadata['echopay_error_timestamp'] = now()->toIso8601String();

            $payment->metadata = $currentMetadata;
            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->save();

            throw new ValidationException('Error initiating payer authentication: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('Error initiating payer authentication: ' . $e->getMessage());
        }
    }

    public function completeDeviceData(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderId'];
        $user = auth()->user();

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        if ($order->isPaid()) {
            return [
                'status' => 'error',
                'message' => 'Order is already paid',
                'data' => [],
            ];
        }

        $payment = Payments::getLatestForEntity($order);

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $order = $payment->order;

        try {
            $previousStatus = $payment->status;
            $payment->update(['status' => PaymentStatusEnum::PROCESSING->value]);

            // If payment is already authorized, jump directly to ProcessPaymentAction
            if ($previousStatus === PaymentStatusEnum::AUTHORIZED->value) {
                $authorizationData = ConsumerAuthentication::from(json_decode($order->get('authorization_data') ?? '{}', true));
                $result = new ProcessPaymentAction($app, $payment, $order)->execute($authorizationData);

                return [
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'data' => $result['data'],
                ];
            }

            if (in_array($previousStatus, [PaymentStatusEnum::WAITING_DEVICE_DATA->value, PaymentStatusEnum::PENDING_AUTHORIZATION->value, PaymentStatusEnum::PENDING->value])) {
                $paymentProcessor = new PortalPaymentProcessor(
                    $app,
                    $payment->company,
                    []
                );

                $enrollmentResult = $paymentProcessor->completeDeviceData($payment);

                if ($enrollmentResult['status'] === PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
                    $order->set('authorization_data', json_encode($enrollmentResult['data']));

                    return [
                        'status' => $enrollmentResult['status'],
                        'message' => $enrollmentResult['message'],
                        'data' => $enrollmentResult['data'],
                    ];
                }

                if ($enrollmentResult['status'] != 'success') {
                    $order->set('payment_status', PaymentStatusEnum::FAILED->value);
                    $order->save();

                    return [
                        'status' => $enrollmentResult['status'],
                        'message' => $enrollmentResult['message'],
                        'data' => $enrollmentResult['data'],
                    ];
                }

                $result = new ProcessPaymentAction($app, $payment, $order)->execute($enrollmentResult['data']);

                return [
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'data' => $result['data'],
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Payment is not waiting for device data: ' . $payment->status,
                    'data' => [],
                ];
            }
        } catch (EchoPayException $e) {
            // Save error body to payment metadata
            $currentMetadata = $payment->metadata ?? [];
            $currentMetadata['echopay_error'] = $e->getErrorBody();
            $currentMetadata['echopay_error_message'] = $e->getMessage();
            $currentMetadata['echopay_error_timestamp'] = now()->toIso8601String();

            $payment->metadata = $currentMetadata;
            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->save();

            $order->set('payment_status', PaymentStatusEnum::FAILED->value);
            $order->save();

            return [
                'status' => 'error',
                'message' => 'Error completing device data: ' . $e->getMessage(),
                'data' => [],
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error completing device data: ' . $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function validatePayerAuthResult(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderId'];

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        if ($order->isPaid()) {
            return [
                'status' => 'error',
                'message' => 'Order is already paid',
                'data' => [],
            ];
        }

        $payment = Payments::getLatestForEntity($order);

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $order = $payment->order;
        $authTransactionId = $order->get(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value);

        if (! $authTransactionId) {
            return [
                'status' => 'error',
                'message' => 'Transaction ID mismatch',
                'data' => [],
            ];
        }

        try {
            $previousStatus = $payment->status;
            $payment->update(['status' => PaymentStatusEnum::PROCESSING->value]);

            if ($previousStatus === PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
                $paymentProcessor = new PortalPaymentProcessor(
                    $app,
                    $payment->company,
                    []
                );

                $authTransactionId = $order->get(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value);
                $validationResult = $paymentProcessor->validatePayerAuthResult($payment, $order, $authTransactionId);

                if (in_array($validationResult['status'], [PaymentStatusEnum::PENDING_AUTHORIZATION->value, PaymentStatusEnum::PENDING->value])) {
                    return [
                        'status' => $validationResult['status'],
                        'message' => $validationResult['message'],
                        'data' => $validationResult['data'],
                    ];
                } elseif ($validationResult['status'] === PaymentStatusEnum::FAILED->value) {
                    return [
                        'status' => $validationResult['status'],
                        'message' => $validationResult['message'],
                        'data' => $validationResult['data'],
                    ];
                }

                $result = new ProcessPaymentAction($app, $payment, $order)->execute($validationResult['data']);

                return [
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'data' => $result['data'],
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Payment is not waiting for payer authentication: ' . $payment->status,
                    'data' => [],
                ];
            }
        } catch (EchoPayException $e) {
            // Save error body to payment metadata
            $currentMetadata = $payment->metadata ?? [];
            $currentMetadata['echopay_error'] = $e->getErrorBody();
            $currentMetadata['echopay_error_message'] = $e->getMessage();
            $currentMetadata['echopay_error_timestamp'] = now()->toIso8601String();

            $payment->metadata = $currentMetadata;
            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->save();

            $order->set('payment_status', PaymentStatusEnum::FAILED->value);
            $order->save();

            return [
                'status' => 'error',
                'message' => 'Error validating payer authentication: ' . $e->getMessage(),
                'data' => [],
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error validating payer authentication: ' . $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function validatePayment(mixed $root, array $request): array
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

        $order = $payment->payable;

        if (! $order) {
            return [
                'status' => 'error',
                'message' => 'Order not found for this payment',
            ];
        }

        $validateAction = new ValidatePaymentAction($app, $payment, $order);
        $result = $validateAction->execute();

        return $result;
    }

    public function validatePaymentByOrder(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderId'];

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        if (! $order) {
            return [
                'status' => 'error',
                'message' => 'Order not found',
            ];
        }

        $payment = Payments::getLatestForEntity($order, [
            PaymentStatusEnum::AUTHORIZED->value,
        ]);

        if (! $payment) {
            return [
                'status' => 'error',
                'message' => 'No authorized payment found for this order',
            ];
        }

        $validateAction = new ValidatePaymentAction($app, $payment, $order);
        $result = $validateAction->execute();

        return $result;
    }

    public function capturePayment(mixed $root, array $request): array
    {
        $user = auth()->user();

        if (! $user->isAdmin()) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized. Only administrators can capture payments.',
            ];
        }

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

        try {
            $order = $payment->payable;

            if (! $order) {
                return [
                    'status' => 'error',
                    'message' => 'Order not found for this payment',
                ];
            }

            $captureAction = new CapturePaymentAction($app, $payment, $order);
            $result = $captureAction->execute();

            return [
                'status' => $result['status'],
                'message' => $result['message'] ?? 'Payment captured successfully',
                'payment' => $payment->fresh(),
                'data' => $result['data'] ?? [],
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Error capturing payment: ' . $e->getMessage(),
            ];
        }
    }

    public function reversePayment(mixed $root, array $request): array
    {
        $user = auth()->user();

        if (! $user->isAdmin()) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized. Only administrators can reverse payments.',
            ];
        }

        $app = app(Apps::class);
        $paymentId = (int) $request['paymentId'];
        $reason = $request['reason'] ?? 'Manual reversal';

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

        if (! in_array($payment->status, [PaymentStatusEnum::AUTHORIZED->value, PaymentStatusEnum::PAID->value])) {
            return [
                'status' => 'error',
                'message' => 'Payment cannot be reversed. Current status: ' . $payment->status,
            ];
        }

        try {
            $order = $payment->payable;

            if (! $order) {
                return [
                    'status' => 'error',
                    'message' => 'Order not found for this payment',
                ];
            }

            if (! $payment->payment_intent_id) {
                return [
                    'status' => 'error',
                    'message' => 'Payment intent ID not found',
                ];
            }

            $bankTransaction = $payment->payment_intent_id;

            $paymentProcessor = new PortalPaymentProcessor(
                $app,
                $payment->company,
                []
            );

            $reversalResult = $paymentProcessor->reversePayment($payment, $order, $bankTransaction, $reason);

            $payment->addLog('payment_reversed', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'reason' => $reason,
                'reversed_by' => auth()->user()->id ?? null,
                'reversed_at' => now()->toIso8601String(),
            ]);

            return [
                'status' => 'success',
                'message' => 'Payment reversed successfully',
                'payment' => $payment->fresh(),
                'data' => $reversalResult,
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Error reversing payment: ' . $e->getMessage(),
            ];
        }
    }
}
