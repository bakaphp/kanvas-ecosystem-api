<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Webhooks;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Connectors\Alanube\Client;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\ReceivedEcfXmlParserService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

/**
 * Alanube's reception webhook fires for two things: a vendor's e-CF sent to us, and a commercial approval a
 * customer sent about one of ours. The id is looked up as a received document first and as a received
 * commercial approval second.
 *
 * Alanube's received-document record carries only the header and a link to the signed DGII XML, so the lines
 * and ITBIS come from that XML — the same parser WebPOS reception uses, since the XML is DGII's, not the
 * provider's. The bill action is idempotent on vendor RNC + e-NCF and emits the AP ledger event itself.
 */
#[WorkflowAction(
    name: 'Alanube e-CF Received Webhook',
    description: 'Receiver for Alanube documents.reception: a vendor e-CF sent to this company becomes a draft '
        . 'bill (vendor by RNC, lines and ITBIS from the signed XML); a commercial approval from a customer is '
        . 'recorded on the ledger. Inbound only, idempotent.',
    integration: IntegrationsEnum::ALANUBE,
)]
class AlanubeDocumentReceivedWebhookJob extends AlanubeWebhookJob
{
    public const string APPROVAL_LEDGER_EVENT = 'scribe.ecf.commercial_approval_received';

    #[Override]
    public function execute(): array
    {
        $id = $this->payloadValue('id');

        if ($id === null) {
            return ['message' => 'No id in the payload; nothing to do.'];
        }

        $client = new Client($this->app(), $this->company());
        $document = $client->get($client->scopedPath('received-documents/' . $id));

        if ($document !== null) {
            return $this->ingestDocument($id, $document);
        }

        $approval = $client->get($client->scopedPath('received-commercial-approvals/' . $id));

        if ($approval !== null) {
            return $this->recordApproval($id, $approval);
        }

        return ['message' => "Alanube has no received document or commercial approval {$id}."];
    }

    /**
     * @param array<array-key, mixed> $document
     * @return array<string, mixed>
     */
    private function ingestDocument(string $id, array $document): array
    {
        // NOT_RECEIVED: Alanube refused it on our behalf (bad signature, not addressed to us); there is no e-CF to book.
        if (($document['status'] ?? null) !== 'RECEIVED') {
            return ['message' => 'Document was not received.', 'id' => $id, 'status' => $document['status'] ?? null];
        }

        $xmlUrl = (string) ($document['xml'] ?? '');

        if ($xmlUrl === '') {
            return ['message' => "Received document {$id} has no XML yet.", 'id' => $id];
        }

        $received = new ReceivedEcfXmlParserService()->parse(SafeUrlFetcher::fetch($xmlUrl), EcfProviderEnum::ALANUBE);

        $bill = new CreateBillFromReceivedEcfAction(
            app: $this->app(),
            company: $this->company(),
            user: $this->receiver->user,
            received: $received,
        )->execute();

        return [
            'message' => 'Received e-CF booked as a bill.',
            'id' => $id,
            'bill_id' => $bill->getId(),
            'encf' => $received->encf,
            'vendor_rnc' => $received->vendorTaxId,
        ];
    }

    /**
     * @param array<array-key, mixed> $approval
     * @return array<string, mixed>
     */
    private function recordApproval(string $id, array $approval): array
    {
        if ($this->ledgerEventExists(self::APPROVAL_LEDGER_EVENT, $id)) {
            return ['message' => 'Commercial approval already recorded.', 'id' => $id];
        }

        $this->recordLedgerEvent(
            eventType: self::APPROVAL_LEDGER_EVENT,
            correlationId: $id,
            payload: [
                'provider' => EcfProviderEnum::ALANUBE->value,
                'approval_id' => $id,
                'encf' => $approval['documentNumber'] ?? $approval['encf'] ?? null,
                'buyer_rnc' => $approval['buyerIdentification'] ?? null,
                'status' => $approval['status'] ?? null,
                'commercial_response' => $approval['commercialResponse'] ?? $approval['response'] ?? null,
            ],
        );

        return ['message' => 'Commercial approval recorded.', 'id' => $id];
    }
}
