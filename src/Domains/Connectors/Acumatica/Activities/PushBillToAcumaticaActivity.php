<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Acumatica Push Bill',
    description: 'Pushes an accounts-payable bill into Acumatica ERP. Outbound one-way write; it does nothing '
        . 'unless Acumatica writes are enabled for this app, so attaching it to a read-only tenant is a '
        . 'silent no-op rather than an error.',
    integration: IntegrationsEnum::ACUMATICA,
)]
class PushBillToAcumaticaActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    public function execute(Bill $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! new AcumaticaWriteService($app)->isWriteEnabled()) {
            return ['status' => 'skipped', 'reason' => 'acumatica_write_disabled', 'bill_id' => $entity->getId()];
        }

        if ($entity->source === IntegrationsEnum::ACUMATICA->value || $entity->get(CustomFieldEnum::BILL_ID->value) !== null) {
            return ['status' => 'skipped', 'reason' => 'already_in_acumatica', 'bill_id' => $entity->getId()];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::ACUMATICA,
            integrationOperation: fn (): array => [
                'reference' => new PushBillToAcumaticaAction($entity)->execute(),
            ],
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
