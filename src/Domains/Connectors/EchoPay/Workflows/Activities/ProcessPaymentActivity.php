<?php

namespace Kanvas\Connectors\EchoPay\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Workflows\Activities\CreatePasoRapidoOrderActivity;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use Override;
use Throwable;

class ProcessPaymentActivity extends KanvasActivity implements WorkflowActivityInterface
{

    private PortalPaymentProcessor $paymentProcessor;

    #[Override]
    public function execute(Model $payment, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $payment,
            app: $app,
            integration: IntegrationsEnum::ECHO_PAY,
            integrationOperation: function ($payment, $app, $integrationCompany, $additionalParams) use ($params) {
                if ($payment->paymentMethod->processor !== 'portal') {
                    return [
                        'payment' => $payment->getId(),
                        'status' => 'error',
                        'message' => 'Payment processor is not portal',
                    ];
                }

                $hasMerchantService = $this->setupVendorService($payment->order, $payment->order->orderType, $app);

                if (! $hasMerchantService) {
                    $payment->order->updateQuietly([
                        'status' => OrderStatusEnum::FAILED->value,
                    ]);

                    return [
                        'payment' => $payment->getId(),
                        'status' => 'error',
                        'message' => 'Merchant service is not set for ' . $payment->order->orderType->name,
                    ];
                }

                $order = $payment->order;

                try {
                
                $this->paymentProcessor = new PortalPaymentProcessor(
                    $app,
                    $payment->company,
                    $params
                );

                if ($order->payment_status !== 'paid') {
                    $enrollmentResult = $this->paymentProcessor->makePaymentIntent($payment);
            
                    // If user interaction is pending, stop job and wait
                    if ($order->payment_status === 'pending_action') {
                        return [
                            'payment' => $payment->getId(),
                            'status' => 'pending_action',
                            'message' => 'Payment pending action for order ' . $order->id . '. Waiting for user.',
                        ]; // Job ends here. Will be retriggered later.
                    }
            
                    // If payment still failed, throw to retry
                    if ($order->payment_status !== 'paid') {
                        return [
                            'payment' => $payment->getId(),
                            'status' => 'error',
                            'message' => 'Payment failed or incomplete',
                        ];
                    }
                }

                if ($order->orderType->name === IntegrationsEnum::PASO_RAPIDO->value) {
                    $createPasoRapidoOrderActivity = new CreatePasoRapidoOrderActivity(
                        0,
                        now()->toDateTimeString(),
                        StoredWorkflow::make(),
                        []
                    );

                    $result = $createPasoRapidoOrderActivity->execute($order, $app, $params);
                } else {
                    $order->set(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);
                }



                if ($order->get(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value)) {
                    $this->paymentProcessor->capturePayment($payment, $order->get(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value));
                } else {
                    $this->paymentProcessor->reversePayment($payment, $order->get(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value));
                }

                $order->update([
                    'status' => 'completed',
                ]);

            } catch (Throwable $e) {
                $payment->order->updateQuietly([
                    'status' => OrderStatusEnum::FAILED->value,
                ]);

                $payment->updateQuietly([
                    'status' => PaymentStatusEnum::FAILED->value,
                ]);
    
                $payment->order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($e->getMessage()));
    
                return [
                    'payment' => $payment->getId(),
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'report' => 'fail',
                    'trace' => $e->getTraceAsString(),
                ];
            }

               

                

                
            },
            company: $payment->company,
        );
    }

    private function setUpVendorService(Order $order, OrderTypes $orderType, Apps $app): bool
    {
        $order->set(CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value, $app->get($orderType->name . '_' . CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value));
        $order->set(CustomFieldEnum::ECHO_PAY_CHANNEL_CODE->value, $app->get($orderType->name . '_' . CustomFieldEnum::ECHO_PAY_CHANNEL_CODE->value));
        $order->set(CustomFieldEnum::ECHO_PAY_SERVICE_CODE->value, $app->get($orderType->name . '_' . CustomFieldEnum::ECHO_PAY_SERVICE_CODE->value));
        $order->set(CustomFieldEnum::ECHO_PAY_SERVICE_TYPE_ID->value, $app->get($orderType->name . '_' . CustomFieldEnum::ECHO_PAY_SERVICE_TYPE_ID->value));
        $order->set(CustomFieldEnum::ECHO_PAY_CONTRACT->value, $app->get($orderType->name . '_' . CustomFieldEnum::ECHO_PAY_CONTRACT->value));

        return $order->get(CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value) ?? false;
    }
}
