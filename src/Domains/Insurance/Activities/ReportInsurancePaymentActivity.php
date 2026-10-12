<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\BuildInsurancePaymentReportAction;
use Kanvas\Insurance\Concerns\FailsReleasingHeldPayment;
use Kanvas\Insurance\Concerns\ResolvesReportablePayment;
use Kanvas\Insurance\Contracts\PaymentReportProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class ReportInsurancePaymentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use FailsReleasingHeldPayment;
    use ResolvesReportablePayment;

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (empty($order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value))) {
            return $this->failReleasingHold($order, 'Order has no insurance quote number');
        }

        $payment = $this->reportablePayment($order);

        if ($payment === null) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'Order has no authorized or paid payment to report',
            ]);
        }

        $provider = InsuranceProviderFactory::forOrder($order);

        if (! $provider instanceof PaymentReportProviderInterface) {
            return $this->failReleasingHold($order, $provider->name() . ' does not accept payment reports');
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($provider, $payment) {
                $report = new BuildInsurancePaymentReportAction($order, $payment)->execute();

                try {
                    $result = $provider->reportPayment($order, $report);
                } catch (ValidationException $e) {
                    return $this->failReleasingHold($order, $e->getMessage());
                }

                if (! $result->success) {
                    return $this->failReleasingHold($order, $result->message);
                }

                return [
                    'order' => $order->getId(),
                    'payment' => $payment->getId(),
                    'status' => 'success',
                    'message' => $result->message,
                ];
            },
            company: $order->company,
        );
    }
}
