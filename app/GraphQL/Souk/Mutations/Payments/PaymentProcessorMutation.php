<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Payments\Actions\CreatePaymentMethodAction;
use Kanvas\Payments\DataTransferObjet\PaymentMethod as PaymentMethodData;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;
use RuntimeException;

class PaymentProcessorMutation
{
    public function processPayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        try {
            $payment = Payments::getByIdFromCompanyApp((int) $request['paymentId'], $user->getCurrentCompany(), $app);

            if ($payment->isPaid()) {
                return ['status' => 'error', 'message' => 'Payment is already paid'];
            }

            $order = $payment->order ?? throw new \RuntimeException('Order not found for this payment');

            if ($order->isPaid()) {
                return ['status' => 'error', 'message' => 'Order is already paid'];
            }

            $payment->update(['status' => PaymentStatusEnum::PROCESSING->value]);

            $context = [];
            if (isset($payment->metadata['use_hold'])) {
                $context['use_hold'] = (bool) $payment->metadata['use_hold'];
            }

            $processor = ProcessorFactory::make($payment->paymentMethod?->processor, $app, $payment->company);
            $result = $processor->authorize($payment, $order, $context);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'payment' => $payment->fresh(),
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            $payment?->update(['status' => PaymentStatusEnum::FAILED->value]);

            return ['status' => 'error', 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function capturePayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        try {
            $payment = Payments::getByIdFromCompanyApp((int) $request['paymentId'], $user->getCurrentCompany(), $app);

            if ($payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
                return ['status' => 'error', 'message' => 'Payment is not in authorized status. Current status: ' . $payment->status];
            }

            $order = $payment->order ?? throw new \RuntimeException('Order not found for this payment');
            $amount = isset($request['amount']) ? (float) $request['amount'] : null;

            $processor = ProcessorFactory::make($payment->paymentMethod?->processor ?? $payment->processor, $app, $payment->company);
            $result = $processor->capture($payment, $order, $amount);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'payment' => $payment->fresh(),
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function refundPayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        try {
            $payment = Payments::getByIdFromCompanyApp((int) $request['paymentId'], $user->getCurrentCompany(), $app);

            if (! in_array($payment->status, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::AUTHORIZED->value])) {
                return ['status' => 'error', 'message' => 'Payment cannot be refunded. Current status: ' . $payment->status];
            }

            $order = $payment->order ?? throw new \RuntimeException('Order not found for this payment');
            $amount = isset($request['amount']) ? (float) $request['amount'] : null;

            $processor = ProcessorFactory::make($payment->paymentMethod?->processor, $app, $payment->company);
            $result = $processor->refund($payment, $order, $amount);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'payment' => $payment->fresh(),
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function voidPayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        try {
            $payment = Payments::getByIdFromCompanyApp((int) $request['paymentId'], $user->getCurrentCompany(), $app);
            $order = $payment->order ?? throw new \RuntimeException('Order not found for this payment');

            $processor = ProcessorFactory::make($payment->paymentMethod?->processor ?? $payment->processor, $app, $payment->company);
            $result = $processor->void($payment, $order);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'payment' => $payment->fresh(),
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function startChallenge(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        try {
            $payment = Payments::getByIdFromCompanyApp((int) $request['paymentId'], $user->getCurrentCompany(), $app);

            if ($payment->isPaid()) {
                return ['success' => true, 'message' => 'Payment is already paid', 'status' => PaymentStatusEnum::PAID->value, 'data' => []];
            }

            $order = $payment->order ?? throw new \RuntimeException('Order not found for this payment');

            if ($order->isPaid()) {
                return ['success' => true, 'message' => 'Order is already paid', 'status' => PaymentStatusEnum::PAID->value, 'data' => []];
            }

            $context = ['browser_info' => $request['browserInfo'] ?? []];
            $result = $this->resolveThreeDSProcessor($payment, $app)->startChallenge($payment, $order, $context);

            return [
                'success' => $result->success,
                'message' => $result->message,
                'status' => $result->status,
                'data' => array_merge($result->data, ['raw' => $result->raw]),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'status' => PaymentStatusEnum::FAILED->value, 'data' => null];
        }
    }

    public function startChallengeWithCard(mixed $root, array $request): array
    {
        $app      = app(Apps::class);
        $user     = auth()->user();
        $cardData = $request['paymentData'];

        try {
            $order = Order::where(['apps_id' => $app->getId(), 'id' => (int) $request['orderId']])->firstOrFail();

            if ($order->isPaid()) {
                return ['success' => true, 'message' => 'Order is already paid', 'status' => PaymentStatusEnum::PAID->value, 'data' => []];
            }

            $payment = $this->findOrCreatePayment($cardData, $order, $app, $user);

            if ($payment->isPaid()) {
                return ['success' => true, 'message' => 'Payment is already paid', 'status' => PaymentStatusEnum::PAID->value, 'data' => []];
            }

            // Card number and CVV are passed in-memory only — never persisted to DB (PCI DSS).
            $context = [
                'card_number'  => preg_replace('/\s+/', '', $cardData['number']),
                'cvc'          => $cardData['cvv'] ?? null,
                'browser_info' => $cardData['browser_info'] ?? [],
            ];

            $result = $this->resolveThreeDSProcessor($payment, $app)->startChallenge($payment, $order, $context);

            return [
                'success' => $result->success,
                'message' => $result->message,
                'status'  => $result->status,
                'data'    => [
                    ...$result->data,
                    'raw' => $result->raw,
                    'payment_id' => $payment->id
                ],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'status' => PaymentStatusEnum::FAILED->value,
                'data' => null
            ];
        }
    }

    /**
     * Find an existing in-progress payment for the order (retry after 3DS method step)
     * or create a new one. Only non-sensitive metadata is stored (last 4, billing address).
     */
    private function findOrCreatePayment(array $cardData, Order $order, Apps $app, mixed $user): Payments
    {
        $existing = $order->payments()
            ->whereIn('status', [
                PaymentStatusEnum::PENDING->value,
                PaymentStatusEnum::WAITING_DEVICE_DATA->value,
                PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            ])
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $cardNumber    = preg_replace('/\s+/', '', $cardData['number']);
        $processorName = $cardData['processor'];

        $paymentMethod = new CreatePaymentMethodAction(new PaymentMethodData(
            app: $app,
            user: $user,
            company: $order->company,
            payment_ending_numbers: substr($cardNumber, -4),
            payment_methods_brand: '',
            expiration_date: $cardData['expiration_date'],
            zip_code: $cardData['zip_code'] ?? '',
            processor: $processorName,
            metadata: [
                'firstname' => $cardData['firstname'] ?? null,
                'lastname'  => $cardData['lastname'] ?? null,
                'address'   => $cardData['address'] ?? null,
                'city'      => $cardData['city'] ?? null,
                'state'     => $cardData['state'] ?? null,
                'zip_code'  => $cardData['zip_code'] ?? null,
                'country'   => $cardData['country'] ?? null,
                'phone'     => $cardData['phone'] ?? null,
            ],
        ))->execute();

        $createPayment              = new CreatePaymentAction($order, $user);
        $createPayment->runWorkflow = false;

        return $createPayment->execute(['payment_methods_id' => $paymentMethod->id]);
    }

    public function finalizeChallenge(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        try {
            $payment = Payments::getByIdFromCompanyApp((int) $request['paymentId'], $user->getCurrentCompany(), $app);

            if ($payment->isPaid()) {
                return ['success' => true, 'message' => 'Payment is already paid', 'status' => PaymentStatusEnum::PAID->value, 'data' => []];
            }

            $order = $payment->order ?? throw new \RuntimeException('Order not found for this payment');

            if ($order->isPaid()) {
                return ['success' => true, 'message' => 'Order is already paid', 'status' => PaymentStatusEnum::PAID->value, 'data' => []];
            }

            $result = $this->resolveThreeDSProcessor($payment, $app)->finalizeChallenge($payment, $order);

            return [
                'success' => $result->success,
                'message' => $result->message,
                'status' => $result->status,
                'data' => array_merge($result->data, ['raw' => $result->raw]),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'status' => PaymentStatusEnum::FAILED->value, 'data' => null];
        }
    }

    public function verifyPayment(mixed $root, array $request): array
    {
        $app = app(Apps::class);

        try {
            if (isset($request['paymentId'])) {
                $user = auth()->user();
                $payment = Payments::getByIdFromCompanyApp((int) $request['paymentId'], $user->getCurrentCompany(), $app);
                $order = $payment->order ?? throw new RuntimeException('Order not found for this payment');
            } else {
                $order = Order::where([
                    'apps_id' => $app->getId(),
                    'id' => (int) $request['orderId'],
                ])->firstOrFail();

                $payment = Payments::getLatestForEntity($order, [PaymentStatusEnum::PAID->value])
                    ?? throw new RuntimeException('No payment found for this order');
            }

            $processor = ProcessorFactory::make($payment->paymentMethod?->processor ?? $payment->processor, $app, $payment->company);
            $result = $processor->verify($payment, $order);

            return [
                'status' => $result->success ? 'success' : 'error',
                'message' => $result->message,
                'iso_code' => $result->isoCode,
                'transaction_id' => $result->transactionId,
                'data' => $result->raw,
            ];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function resolveThreeDSProcessor(Payments $payment, Apps $app): ThreeDSProcessorInterface
    {
        $processorName = $payment->paymentMethod?->processor ?? $payment->processor;
        $processor = ProcessorFactory::make($processorName, $app, $payment->company);

        if (! ($processor instanceof ThreeDSProcessorInterface)) {
            throw new RuntimeException("Processor '{$processorName}' does not support 3DS");
        }

        return $processor;
    }
}
