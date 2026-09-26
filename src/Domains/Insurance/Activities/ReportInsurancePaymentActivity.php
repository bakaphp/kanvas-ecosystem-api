<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\BuildInsurancePaymentReportAction;
use Kanvas\Insurance\Contracts\PaymentReportProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Reports an authorized charge to the insurer, which is what unblocks emission.
 * It runs off the authorization rather than the capture: the funds stay on hold
 * until the policy exists, so a failed emission is reversed and never captured.
 */
#[WorkflowAction]
class ReportInsurancePaymentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        /** @var Order $order */
        if (empty($order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value))) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'Order has no insurance quote number',
            ]);
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
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => $provider->name() . ' does not accept payment reports',
            ]);
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($provider, $payment) {
                $report = new BuildInsurancePaymentReportAction($order, $payment)->execute();
                $result = $provider->reportPayment($order, $report);

                return [
                    'order' => $order->getId(),
                    'payment' => $payment->getId(),
                    'status' => $result->success ? 'success' : 'pending',
                    'message' => $result->message,
                ];
            },
            company: $order->company,
        );
    }

    protected function reportablePayment(Order $order): ?Payments
    {
        return $order->payments()
            ->whereIn('status', [
                PaymentStatusEnum::AUTHORIZED->value,
                PaymentStatusEnum::PAID->value,
            ])
            ->orderByDesc('id')
            ->first();
    }
}
