<?php

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Workflows\Activities\ProcessPaymentActivity;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;

class CreatePaymentAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute($formData = []): Payments
    {
        $paymentMethodId = $formData['payment_methods_id'] ?? $this->order->payment_method_id;
        $paymentMethod = PaymentMethods::fromApp($this->order->app)->where('id', $paymentMethodId)->first();

        if (! $paymentMethod) {
            throw new \Exception('Payment method not found');
        }

        $formData = [
            "amount" => $formData['amount'] ?? $this->order->getTotalAmount(),
            "payment_date" => $formData['payment_date'] ?? date("Y-m-d"),
            "concept" => $formData['concept'] ?? "Payment {$this->order->reference}",
            "payment_methods_id" => $paymentMethodId,
            'users_id' => $this->order->users_id,
            'companies_id' => $this->order->companies_id,
            'currency' => $this->order->currency,
            'status' => PaymentStatusEnum::PENDING->value
        ];

        $this->setUpVendorService($this->order);

        $payment = $this->order->payments()->create($formData);
        $this->order->updateQuietly([
            'status' => OrderStatusEnum::PENDING->value,
        ]);

        $activity = new ProcessPaymentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($payment, $this->order->app, []);

        dd($result);

        // if ($this->runWorkflow) {
        //     $payment->fireWorkflow(
        //         WorkflowEnum::CREATED->value,
        //         true,
        //         [
        //             'app' => $this->order->app,
        //         ]
        //     );
        // }

        return $payment;
    }


    private function setUpVendorService(Order $order): void
    {
        $orderTypeName = "paso_rapido";

        $this->order->app->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_ECHO_PAY_CLIENT_ID'));
        $this->order->app->set(ConfigurationEnum::SECRET->value, env('TEST_ECHO_PAY_SECRET'));
        $this->order->app->set(ConfigurationEnum::APP_TOKEN->value, env('TEST_ECHO_PAY_APP_TOKEN'));
        $this->order->app->set(ConfigurationEnum::MERCHANT_ID->value, env('TEST_ECHO_PAY_MERCHANT_ID'));
        $this->order->app->set(ConfigurationEnum::MERCHANT_IDENTIFIER->value, env('TEST_ECHO_PAY_MERCHANT_IDENTIFIER'));
        $this->order->app->set(ConfigurationEnum::MERCHANT_KEY->value, env('TEST_ECHO_PAY_MERCHANT_KEY'));
        $this->order->app->set(ConfigurationEnum::MERCHANT_SECRET->value, env('TEST_ECHO_PAY_MERCHANT_SECRET'));

        $this->order->app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value, env('TEST_ECHO_PAY_MERCHANT_SERVICE_KEY'));
        $this->order->app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_CHANNEL_CODE->value, env('TEST_ECHO_PAY_CHANNEL_CODE'));
        $this->order->app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_SERVICE_CODE->value, env('TEST_ECHO_PAY_SERVICE_CODE'));
        $this->order->app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_SERVICE_TYPE_ID->value, env('TEST_ECHO_PAY_SERVICE_TYPE_ID'));
        $this->order->app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_CONTRACT->value, env('TEST_ECHO_PAY_CONTRACT'));
    }
}
