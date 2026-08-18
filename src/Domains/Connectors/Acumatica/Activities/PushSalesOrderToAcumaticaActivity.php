<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Actions\PushSalesOrderToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Acumatica Push Sales Order',
    description: 'Pushes a sales order into Acumatica ERP. Outbound one-way write, gated on Acumatica writes '
        . 'being enabled for this app.',
    integration: IntegrationsEnum::ACUMATICA,
)]
class PushSalesOrderToAcumaticaActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @return array<array-key, mixed>
     */
    public function execute(Order $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! new AcumaticaWriteService($app)->isWriteEnabled()) {
            return ['status' => 'skipped', 'reason' => 'acumatica_write_disabled', 'order_id' => $entity->getId()];
        }

        if ($entity->get(CustomFieldEnum::ORDER_ID->value) !== null) {
            return ['status' => 'skipped', 'reason' => 'already_in_acumatica', 'order_id' => $entity->getId()];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::ACUMATICA,
            integrationOperation: fn (): array => [
                'reference' => new PushSalesOrderToAcumaticaAction($entity)->execute(),
            ],
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
