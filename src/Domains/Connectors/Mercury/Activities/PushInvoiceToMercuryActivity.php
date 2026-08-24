<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mercury\Actions\PushInvoiceToMercuryAction;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Mercury Push Invoice',
    description: 'Raises the invoice in Mercury so the bank can collect it. Outbound write — Mercury may email '
        . 'the customer the invoice itself, so treat this as customer-visible even though Kanvas sends '
        . 'nothing.',
    integration: IntegrationsEnum::MERCURY,
)]
class PushInvoiceToMercuryActivity extends KanvasActivity
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

        // The echo guard. Pushing back an invoice we PULLED from Mercury would create a second one, which we
        // would then pull, and bill the customer afresh on every cycle.
        if ($entity->source === IntegrationsEnum::MERCURY->value) {
            return $this->skip('originated_in_mercury', $entity);
        }

        if (! empty($entity->get(CustomFieldEnum::INVOICE_ID->value))) {
            return $this->skip('already_in_mercury', $entity);
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
            integration: IntegrationsEnum::MERCURY,
            integrationOperation: function () use ($entity): array {
                $created = new PushInvoiceToMercuryAction($entity)->execute();

                return [
                    'mercury_invoice_id' => $created->id,
                    'pay_page_url' => $created->payPageUrl(),
                ];
            },
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
