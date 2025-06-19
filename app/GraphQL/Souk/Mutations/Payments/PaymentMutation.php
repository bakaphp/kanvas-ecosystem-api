<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use App\Domains\Connectors\Movipass\Actions\ProcessPaymentAction;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Actions\MakePaymentIntentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;

class PaymentMutation
{
    public function makePaymentIntent($_, array $request): array
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
            "paymentIntent" => $paymentIntent->execute(),
            "message" => "message",
        ];
    }

    public function makePaymentIntentFromOrder($_, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderID'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'payable_id' => $orderId,
            'payable_type' => Order::class,
        ])->first();

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $paymentIntent = new MakePaymentIntentAction($payment);

        return [
            "paymentIntent" => $paymentIntent->execute(),
            "message" => "message",
        ];
    }

    public function addPaymentToOrder($_, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderID'];

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        if ($order->isFulfilled()) {
            throw new ValidationException('Order is already fulfilled');
        }

        if ($order->isCompleted()) {
            throw new ValidationException('Order is already completed');
        }

        $formData = $request['input'];

        if ($order->metadata && isset($order->metadata['data']['payment_methods_id'])) {
            $payment = new CreatePaymentAction($order)->execute($formData);
        }

        return [
            "payment" => $payment,
            "message" => "message",
        ];
    }

    public function initiatePayerAuthentication($_, array $request)
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderId'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'payable_id' => $orderId,
            'payable_type' => Order::class,
        ])->first();

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

        $paymentProcessor = new PortalPaymentProcessor(
            $app,
            $payment->company,
            []
        );

        $session = $paymentProcessor->startPaymentIntent($payment);

        $consumerAuthenticationInformation = $session['consumerAuthenticationInformation'];

        $payment->order->addAttribute('auth_session_id', $consumerAuthenticationInformation['referenceId']);
        $payment->order->addAttribute('device_data_url', $consumerAuthenticationInformation['deviceDataCollectionUrl']);
        $payment->order->addAttribute('access_token', $consumerAuthenticationInformation['accessToken']);
        $payment->order->addAttribute('payment_status', 'waiting_device_data');

        $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA;
        $payment->save();


        return [
            'deviceDataUrl' =>   $consumerAuthenticationInformation['deviceDataCollectionUrl'],
            'accessToken' => $consumerAuthenticationInformation['accessToken'],
            'referenceId' => $consumerAuthenticationInformation['referenceId'],
            'message' => 'Waiting for device data',
            'status' => 'waiting_device_data',
        ];
    }

    public function completeDeviceData($_, array $request)
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderId'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'payable_id' => $orderId,
            'payable_type' => Order::class,
        ])->first();

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        if ($payment->status === PaymentStatusEnum::WAITING_DEVICE_DATA) {
            $paymentProcessor = new PortalPaymentProcessor(
                $app,
                $payment->company,
                []
            );

            $completeDeviceDataResult = $paymentProcessor->completeDeviceData($payment, $request['deviceData']);

            if ($completeDeviceDataResult['status'] === 'pending_action') {
                return [
                    'status' => 'pending_action',
                    'message' => 'Payment pending action for order ' . $payment->order->id . '. Waiting for user.',
                    'data' => [
                        "challengeUrl" => $completeDeviceDataResult["data"]["challengeUrl"],
                        "accessToken" => $completeDeviceDataResult["data"]["accessToken"],
                        "referenceId" => $completeDeviceDataResult["data"]["referenceId"],
                    ],
                ];
            }

            $result = new ProcessPaymentAction($app, $payment)->execute();

            return [
                'status' => $result['status'],
                'message' => $result['message'],
                'data' => $result['data'],
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'Payment is not waiting for device data',
            ];
        }
    }
}
