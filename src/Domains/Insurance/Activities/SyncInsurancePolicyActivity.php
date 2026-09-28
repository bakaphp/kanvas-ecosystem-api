<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Contracts\PolicyProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Pay and emit often complete on the insurer's side out of band, so this polls the
 * policy back. Provider-agnostic: it resolves whichever insurer the order was
 * quoted with, so a new insurer needs no new activity.
 */
#[WorkflowAction]
class SyncInsurancePolicyActivity extends KanvasActivity implements WorkflowActivityInterface
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
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Order has no insurance quote number',
            ];
        }

        $provider = InsuranceProviderFactory::forOrder($order);

        if (! $provider instanceof PolicyProviderInterface) {
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
