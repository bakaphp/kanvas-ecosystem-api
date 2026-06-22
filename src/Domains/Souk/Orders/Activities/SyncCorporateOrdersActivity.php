<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\AssignCorporateOrderOwnerAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class SyncCorporateOrdersActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $reassigned = new AssignCorporateOrderOwnerAction($order)->execute();

                if ($reassigned === null) {
                    return [
                        'result' => false,
                        'message' => 'Order is not a corporate order or already owned by the company owner.',
                        'order_id' => $order->getId(),
                    ];
                }

                return [
                    'result' => true,
                    'message' => 'Corporate order reassigned to the company owner.',
                    'order_id' => $reassigned->getId(),
                    'new_user_id' => (int) $reassigned->users_id,
                    'actor_user_id' => (int) ($reassigned->metadata['data'][AssignCorporateOrderOwnerAction::ACTOR_METADATA_KEY] ?? 0),
                ];
            },
            company: $order->company,
        );
    }
}
