<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Actions\PushPaymentToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Scribe\Payments\Models\Payment;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Acumatica Push Payment',
    description: 'Pushes a payment into Acumatica ERP so the ledger there matches Kanvas. Outbound one-way '
        . 'write, gated on Acumatica writes being enabled for this app.',
    integration: IntegrationsEnum::ACUMATICA,
)]
class PushPaymentToAcumaticaActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @return array<array-key, mixed>
     */
    public function execute(Payment $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! new AcumaticaWriteService($app)->isWriteEnabled()) {
            return ['status' => 'skipped', 'reason' => 'acumatica_write_disabled', 'payment_id' => $entity->getId()];
        }

        if ($entity->source === IntegrationsEnum::ACUMATICA->value || $entity->get(CustomFieldEnum::PAYMENT_ID->value) !== null) {
            return ['status' => 'skipped', 'reason' => 'already_in_acumatica', 'payment_id' => $entity->getId()];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::ACUMATICA,
            integrationOperation: fn (): array => [
                'reference' => new PushPaymentToAcumaticaAction($entity)->execute(),
            ],
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
