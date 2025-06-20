<?php

namespace Kanvas\Connectors\EchoPay\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Actions\ProcessPaymentAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class ProcessPaymentActivity extends KanvasActivity implements WorkflowActivityInterface
{
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

                $order = $payment->order;
                $paymentProcessor = new PortalPaymentProcessor(
                    $app,
                    $payment->company
                );

                // @TODO: Deprecated this should be removed after testing
                $session = $paymentProcessor->startPaymentIntent($payment);

                $consumerAuthenticationInformation = $session['consumerAuthenticationInformation'];

                $payment->order->set('auth_session_id', $consumerAuthenticationInformation['referenceId']);
                $payment->order->set('device_data_url', $consumerAuthenticationInformation['deviceDataCollectionUrl']);
                $payment->order->set('access_token', $consumerAuthenticationInformation['accessToken']);
                $payment->order->set('payment_status', 'waiting_device_data');

                $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA;
                $payment->save();

                $enrollmentResult = $paymentProcessor->completeDeviceData($payment);

                if ($enrollmentResult['status'] === PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
                    $order->set("authorization_data", json_encode($enrollmentResult['data']));

                    return [
                        'status' => $enrollmentResult['status'],
                        'message' => 'Payment pending action for order ' . $order->id . '. Waiting for user.',
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
                    'payment' => $payment->getId(),
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'data' => $result['data'],
                    'response' => $result['response'] ?? null,
                ];
            },
            company: $payment->company,
        );
    }
}
