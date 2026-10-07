<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\SettleInsurancePaymentAction;
use Kanvas\Insurance\Contracts\InspectionProviderInterface;
use Kanvas\Insurance\Contracts\PolicyProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs once the payment has been reported. The charge is still a hold at this
 * point, so a refusal from the insurer releases it instead of refunding.
 */
#[WorkflowAction]
class EmitInsurancePolicyActivity extends KanvasActivity implements WorkflowActivityInterface
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

        $provider = InsuranceProviderFactory::forOrder($order);

        if (! $provider instanceof PolicyProviderInterface) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => $provider->name() . ' does not emit policies',
            ]);
        }

        $status = (string) $order->get(InsuranceCustomFieldEnum::STATUS->value);

        if (! in_array($status, [InsuranceStatusEnum::PAID->value, InsuranceStatusEnum::EMITTED->value], true)) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'Payment has not been reported to the insurer yet (status: ' . $status . ')',
            ]);
        }

        if (
            $provider instanceof InspectionProviderInterface
            && $provider->requiresInspection($order)
            && trim((string) $order->get(InsuranceCustomFieldEnum::UPLOADED_DOCUMENTS->value)) === ''
        ) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'Inspection documents are required before emission',
            ]);
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($provider) {
                $settlement = new SettleInsurancePaymentAction($order);

                try {
                    $policy = $provider->emit($order);
                } catch (ValidationException $e) {
                    $order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::FAILED->value);
                    $released = $settlement->release();

                    return $this->failWorkflow([
                        'order' => $order->getId(),
                        'message' => $e->getMessage(),
                        'paymentReleased' => $released === true,
                    ]);
                }

                $captured = $policy->success ? $settlement->capture() : null;

                return [
                    'order' => $order->getId(),
                    'status' => $policy->success ? 'success' : 'pending',
                    'message' => $policy->message,
                    'policyNumber' => $policy->policyNumber,
                    'paymentCaptured' => $captured === true,
                ];
            },
            company: $order->company,
        );
    }
}
