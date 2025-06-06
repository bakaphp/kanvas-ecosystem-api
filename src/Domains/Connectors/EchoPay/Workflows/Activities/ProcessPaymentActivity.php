<?php

namespace Kanvas\Connectors\EchoPay\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class ProcessPaymentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $payment, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);


        return $this->executeIntegration(
            entity: $payment,
            app: $app,
            integration: IntegrationsEnum::ECHO_PAY,
            integrationOperation: function ($payment, $app, $integrationCompany, $additionalParams) {
                if ($payment->paymentMethod->processor !== 'portal') {
                    return [
                        'payment' => $payment->getId(),
                        'status' => 'error',
                        'message' => 'Payment processor is not portal',
                    ];
                }

                $hasMerchantService = $this->setupVendorService($payment->order, $payment->order->orderType, $app);

                if (!$hasMerchantService) {
                    return [
                        'payment' => $payment->getId(),
                        'status' => 'error',
                        'message' => 'Merchant service is not set for ' . $payment->order->orderType->name,
                    ];
                }

                $paymentProcessor = new PortalPaymentProcessor(
                    $app,
                    $payment->company
                );

                $result = $paymentProcessor->makePaymentIntent($payment);

                return [
                    'payment' => $payment->getId(),
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'result' => $result['data'],
                    'response' => $result['response'] ?? null,
                ];
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

        return $order->get(CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value);
    }
}
