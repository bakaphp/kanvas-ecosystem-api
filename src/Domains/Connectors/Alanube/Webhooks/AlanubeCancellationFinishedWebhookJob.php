<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Webhooks;

use Kanvas\Connectors\Alanube\Client;
use Kanvas\Connectors\Alanube\Enums\DocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

/**
 * A finished e-NCF range cancellation (`POST /cancellations`) becomes one ledger event per cancellation id.
 */
#[WorkflowAction(
    name: 'Alanube e-NCF Cancellation Finished Webhook',
    description: 'Receiver for Alanube documents.cancellations: re-reads the range cancellation from Alanube '
        . 'and, once finished, records it on the ledger once. Inbound only.',
    integration: IntegrationsEnum::ALANUBE,
)]
class AlanubeCancellationFinishedWebhookJob extends AlanubeWebhookJob
{
    public const string LEDGER_EVENT = 'scribe.ecf.range_cancelled';

    #[Override]
    public function execute(): array
    {
        $id = $this->payloadValue('id');

        if ($id === null) {
            return ['message' => 'No cancellation id in the payload; nothing to do.'];
        }

        // The bare id is the correlation id: the event type already says what it is, and a ULID fits the 36 characters.
        if ($this->ledgerEventExists(self::LEDGER_EVENT, $id)) {
            return ['message' => 'Cancellation already recorded.', 'id' => $id];
        }

        $client = new Client($this->app(), $this->company());
        $cancellation = $client->get($client->scopedPath('cancellations/' . $id));

        if ($cancellation === null) {
            return ['message' => "Alanube has no cancellation {$id}."];
        }

        if (DocumentStatusEnum::tryFrom((string) ($cancellation['status'] ?? '')) !== DocumentStatusEnum::FINISHED) {
            return ['message' => 'Cancellation not finished yet.', 'id' => $id, 'status' => $cancellation['status'] ?? null];
        }

        $this->recordLedgerEvent(
            eventType: self::LEDGER_EVENT,
            correlationId: $id,
            payload: [
                'provider' => EcfProviderEnum::ALANUBE->value,
                'cancellation_id' => $id,
                'stamp_date' => $cancellation['stampDate'] ?? null,
                'legal_status' => $cancellation['legalStatus'] ?? null,
                'cancellations' => $cancellation['cancellations'] ?? null,
            ],
        );

        return ['message' => 'Cancellation recorded.', 'id' => $id];
    }
}
