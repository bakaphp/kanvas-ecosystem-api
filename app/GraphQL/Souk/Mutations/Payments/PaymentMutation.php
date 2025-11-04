<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\Movipass\Actions\ProcessPaymentAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Actions\MakePaymentIntentAction;
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

        $formData = $request['input'];

        $paymentMethodId = $formData['payment_methods_id'] ?? $order->metadata['data']['payment_methods_id'] ?? null;

        if (! $paymentMethodId) {
            return [
                'status' => 'error',
                'message' => 'Payment method not found',
            ];
        }

        try {
            $formData['amount'] = $formData['amount'] ?? $order->getTotalAmount();
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

        $payment = Payments::getLatestForOrder($order);

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $order = $payment->order;

        try {
            // If payment is already authorized, jump directly to ProcessPaymentAction
            if ($payment->status === PaymentStatusEnum::AUTHORIZED->value) {
                $authorizationData = ConsumerAuthentication::from(json_decode($order->get('authorization_data') ?? '{}', true));
                $result = new ProcessPaymentAction($app, $payment, $order)->execute($authorizationData);

                return [
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'data' => $result['data'],
                ];
            }

            if (in_array($payment->status, [PaymentStatusEnum::WAITING_DEVICE_DATA->value, PaymentStatusEnum::PENDING_AUTHORIZATION->value, PaymentStatusEnum::PENDING->value])) {
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

        $payment = Payments::forOrder($orderId, Order::class)->latest()->first();

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
            if ($payment->status === PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
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
}
