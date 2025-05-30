<?php

namespace Kanvas\Connectors\EchoPay\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Payments\Providers\AuthorizePortalPaymentProcessor;
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

                $paymentProcessor = new AuthorizePortalPaymentProcessor(
                    $app,
                    $payment->company
                );

                $result = $paymentProcessor->makePaymentIntent($payment);

                return [
                    'payment' => $payment->getId(),
                    'status' => 'success',
                    'message' => 'Payment confirmed',
                    'result' => $result,
                ];
            },
            company: $payment->company,
        );
    }
}
