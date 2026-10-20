<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Contracts\PolicySyncProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Insurance Sync Policy',
    description: 'Polls the insurer for the current state of a policy and returns whether it is ready, with the policy number when it is. '
        . 'Use it on a schedule or on a status change for orders whose emission came back pending. It is read-only toward the customer: it does not charge, email or cancel anything. '
        . 'It skips orders without a quote number and insurers that do not expose policies.',
)]
class SyncInsurancePolicyActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (InsuranceOrderOriginEnum::isRegistered($order)) {
            return InsuranceOrderOriginEnum::purchaseFlowSkipped($order);
        }

        if (empty($order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value))) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Order has no insurance quote number',
            ];
        }

        $provider = InsuranceProviderFactory::forOrder($order);

        if (! $provider instanceof PolicySyncProviderInterface) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => $provider->name() . ' does not expose policies',
            ];
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($provider) {
                $policy = $provider->syncPolicy($order);

                return [
                    'order' => $order->getId(),
                    'status' => $policy->success ? 'success' : 'pending',
                    'message' => $policy->message,
                    'policyNumber' => $policy->policyNumber,
                ];
            },
            company: $order->company,
        );
    }
}
