<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Webhooks;

use Kanvas\Connectors\Acumatica\Actions\PullBillsAction;
use Kanvas\Connectors\Acumatica\Actions\PullInvoicesAction;
use Kanvas\Connectors\Acumatica\Actions\PullSalesOrdersAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * Near-real-time inbound: Acumatica (or an n8n relay) POSTs a change event and we re-sync just that
 * one record by re-running the existing bulk pull action targeted to its RefNbr. Deliberately minimal
 * — no parallel single-record logic; the pulls already own the mapping and are idempotent, so a
 * re-delivered webhook is a harmless no-op.
 *
 * Read source is the SQL replica (which lags a snapshot cadence), so a webhook can arrive before the
 * replica has the change — accepted for now; the scheduled incremental sync backstops any miss. If lag
 * proves real, switch the targeted fetch to a single-record REST read.
 *
 * Payload: { entity: 'bill'|'invoice'|'salesorder', ref: '<RefNbr / OrderNbr>' }.
 * Receiver config: { acumatica_company_id: <int> }.
 */
#[WorkflowAction(
    name: 'Acumatica Inbound Change Webhook',
    description: 'Receiver for change events POSTed by Acumatica (or an n8n relay): re-pulls the one record '
        . 'named in the payload so Kanvas catches up without waiting for the scheduled sync. Inbound '
        . 'only. Re-delivery is harmless — the pull is idempotent. Payload is {entity: '
        . 'bill|invoice|salesorder, ref: <RefNbr>}; the receiver needs acumatica_company_id in its '
        . 'config.',
    integration: IntegrationsEnum::ACUMATICA,
)]
class ProcessAcumaticaWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $user = $this->receiver->user;
        $acumaticaCompanyId = (int) ($this->receiver->configuration['acumatica_company_id'] ?? 0);

        $entity = strtolower((string) ($payload['entity'] ?? $payload['type'] ?? ''));
        $ref = (string) ($payload['ref'] ?? $payload['refNbr'] ?? $payload['RefNbr'] ?? $payload['id'] ?? '');

        if ($ref === '' || $acumaticaCompanyId === 0) {
            return ['message' => 'missing ref or acumatica_company_id in webhook', 'entity' => $entity];
        }

        $synced = match ($entity) {
            'bill', 'apbill', 'ap' => new PullBillsAction(
                $app,
                $company,
                $user,
                $acumaticaCompanyId,
                ref: $ref
            )->execute(),
            'invoice', 'arinvoice', 'ar' => new PullInvoicesAction(
                $app,
                $company,
                $user,
                $acumaticaCompanyId,
                ref: $ref
            )->execute(),
            'salesorder', 'order', 'so' => $this->syncSalesOrder(
                $app,
                $company,
                $user,
                $acumaticaCompanyId,
                $ref
            ),
            default => null,
        };

        if ($synced === null) {
            return ['message' => "unhandled entity: {$entity}", 'entity' => $entity, 'ref' => $ref];
        }

        return ['message' => "synced {$synced} {$entity} record(s)", 'entity' => $entity, 'ref' => $ref, 'synced' => $synced];
    }

    private function syncSalesOrder(mixed $app, mixed $company, mixed $user, int $acumaticaCompanyId, string $ref): int
    {
        $region = Regions::fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->first();

        if ($region === null) {
            return 0;
        }

        return new PullSalesOrdersAction(
            $app,
            $company,
            $user,
            $region,
            $acumaticaCompanyId,
            orderNumber: $ref
        )->execute();
    }
}
