<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Actions\PushInvoiceToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Acumatica Push Invoice',
    description: 'Pushes an issued accounts-receivable invoice into Acumatica ERP. Outbound one-way write; '
        . 'it does nothing unless Acumatica writes are enabled for this app.',
    integration: IntegrationsEnum::ACUMATICA,
)]
class PushInvoiceToAcumaticaActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    public function execute(Invoice $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! new AcumaticaWriteService($app)->isWriteEnabled()) {
            return $this->skip('acumatica_write_disabled', $entity);
        }

        if ($entity->source === IntegrationsEnum::ACUMATICA->value) {
            return $this->skip('originated_in_acumatica', $entity);
        }

        if (! empty($entity->get(CustomFieldEnum::INVOICE_ID->value))) {
            return $this->skip('already_in_acumatica', $entity);
        }

        if (! in_array($entity->document_status, [
            InvoiceDocumentStatusEnum::ISSUED,
            InvoiceDocumentStatusEnum::SENT,
        ], true)) {
            return $this->skip('not_issued', $entity);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::ACUMATICA,
            integrationOperation: fn (): array => [
                'reference' => new PushInvoiceToAcumaticaAction($entity)->execute(),
            ],
            additionalParams: $params,
            company: $entity->company,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function skip(string $reason, Invoice $entity): array
    {
        return ['status' => 'skipped', 'reason' => $reason, 'invoice_id' => $entity->getId()];
    }
}
