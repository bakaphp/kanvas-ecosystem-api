<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Actions\BulkRechargeOrderTagsAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class BulkRechargeTagsActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 1;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                /** @var Order $order */
                if ($order->orderType?->name !== OrderTypeEnum::PASO_RAPIDO->value) {
                    return ['skipped' => true, 'reason' => 'order is not paso_rapido'];
                }

                if (($order->metadata['data']['is_bulk_recharge'] ?? false) !== true) {
                    return ['skipped' => true, 'reason' => 'order is not flagged is_bulk_recharge'];
                }

                if ($order->payment_status !== 'paid') {
                    return ['skipped' => true, 'reason' => 'order is not paid'];
                }

                if (isset($order->metadata['corporate_recharge_results'])) {
                    return ['skipped' => true, 'reason' => 'already processed'];
                }

                return new BulkRechargeOrderTagsAction($order, $app)->execute();
            },
            company: $entity->company,
        );
    }
}
