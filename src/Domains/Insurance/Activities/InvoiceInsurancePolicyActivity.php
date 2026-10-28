<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Insurance\Actions\BuildInsurancePaymentReportAction;
use Kanvas\Insurance\Concerns\ResolvesReportablePayment;
use Kanvas\Insurance\Contracts\PaymentReportProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Insurance Invoice Policy',
    description: 'Sends the insurer the invoicing data for an issued policy, built from the order\'s payment, and stamps the invoiced date on the order. '
        . 'Use it after the policy is emitted. It runs once per order: it skips when the policy was already invoiced and fails when there is no policy number or no payment to invoice. '
        . 'It only talks to the insurer: it does not charge anyone and does not contact the customer.',
)]
class InvoiceInsurancePolicyActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use ResolvesReportablePayment;

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (InsuranceOrderOriginEnum::isRegistered($order)) {
            return InsuranceOrderOriginEnum::purchaseFlowSkipped($order);
        }

        if (! empty($order->get(InsuranceCustomFieldEnum::INVOICED_AT->value))) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Policy was already invoiced',
            ];
        }

        if (empty($order->get(InsuranceCustomFieldEnum::POLICY_NUMBER->value))) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'Order has no policy number — emit it first',
            ]);
        }

        $payment = $this->reportablePayment($order);

        if ($payment === null) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'Order has no payment to invoice',
            ]);
        }

        $provider = InsuranceProviderFactory::forOrder($order);

        if (! $provider instanceof PaymentReportProviderInterface) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => $provider->name() . ' does not invoice policies',
            ]);
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($provider, $payment) {
                $report = new BuildInsurancePaymentReportAction($order, $payment)->execute();
                $result = $provider->invoicePolicy($order, $report);

                if ($result->success) {
                    $order->set(InsuranceCustomFieldEnum::INVOICED_AT->value, Carbon::now()->toIso8601String());
                }

                return [
                    'order' => $order->getId(),
                    'status' => $result->success ? 'success' : 'pending',
                    'message' => $result->message,
                ];
            },
            company: $order->company,
        );
    }
}
