<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\UniversalSeguros\Actions\SyncPolicyStatusAction;
use Kanvas\Connectors\UniversalSeguros\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class SyncUniversalSegurosPolicyActivity extends KanvasActivity implements WorkflowActivityInterface
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

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::UNIVERSAL_SEGUROS,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                /** @var Order $order */
                if (empty($order->get(CustomFieldEnum::QUOTE_NUMBER->value))) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'Order has no Universal Seguros quote number',
                    ];
                }

                $policy = new SyncPolicyStatusAction($order)->execute();

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'policyNumber' => $policy['numeroPoliza'] ?? null,
                ];
            },
            company: $order->company,
        );
    }
}
