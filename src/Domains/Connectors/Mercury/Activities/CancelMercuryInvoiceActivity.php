<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mercury\Actions\CancelMercuryInvoiceAction;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Mercury Cancel Invoice',
    description: 'Cancels the invoice\'s counterpart in Mercury. Outbound write to the bank; the customer is '
        . 'not contacted by Kanvas, but Mercury may stop its own reminders as a result.',
    integration: IntegrationsEnum::MERCURY,
)]
class CancelMercuryInvoiceActivity extends KanvasActivity
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

        if ($entity->document_status !== InvoiceDocumentStatusEnum::VOIDED) {
            return $this->skip('not_voided', $entity);
        }

        if (empty($entity->get(CustomFieldEnum::INVOICE_ID->value))) {
            return $this->skip('never_pushed_to_mercury', $entity);
        }

        $action = new CancelMercuryInvoiceAction($entity);

        // A voided invoice keeps getting touched, and this activity keeps firing. Mercury answers a second
        // cancel with a 400.
        if ($action->alreadyCancelled()) {
            return $this->skip('already_cancelled', $entity);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::MERCURY,
            integrationOperation: fn (): array => ['cancelled' => $action->execute()],
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
