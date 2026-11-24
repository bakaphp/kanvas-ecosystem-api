<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Webhooks;

use Kanvas\Connectors\Alanube\AlanubeEcfProvider;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfDocumentLookup;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

/**
 * Re-reads the document from Alanube and applies it to the exact fiscal row it was sent as, through the same
 * deduped action the submit and the poll use — so a webhook and a poll delivering the same result land once.
 */
#[WorkflowAction(
    name: 'Alanube e-CF Emission Finished Webhook',
    description: 'Receiver for Alanube documents.emissionFinished: re-reads the e-CF from Alanube and records '
        . 'the DGII result (accepted, accepted with observations, rejected) on the invoice, bill or expense. '
        . 'Inbound only.',
    integration: IntegrationsEnum::ALANUBE,
)]
class AlanubeEmissionFinishedWebhookJob extends AlanubeWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $alanubeId = $this->payloadValue('id');
        $encf = $this->payloadValue('documentNumber') ?? $this->payloadValue('encf');

        if ($alanubeId === null && $encf === null) {
            return ['message' => 'No document id or e-NCF in the payload; nothing to do.'];
        }

        $fiscal = EcfDocumentLookup::byProviderReference(
            $this->app(),
            $this->company(),
            $alanubeId,
            $encf
        );

        if ($fiscal === null) {
            return ['message' => 'No fiscal document for this e-CF in this company.', 'id' => $alanubeId, 'encf' => $encf];
        }

        $alanubeId ??= $fiscal->submissions()->whereNotNull('provider_ref')->latest('attempt')->value('provider_ref');
        $type = EcfTypeEnum::tryFrom($fiscal->document_type);

        if ($alanubeId === null || $type === null) {
            return [
                'message' => "Fiscal document {$fiscal->getId()} has no Alanube id or an unknown e-CF type.",
                'encf' => $fiscal->number,
            ];
        }

        $answer = new AlanubeEcfProvider($this->app(), $this->company(), $fiscal->ecfEnvironment())
            ->fetchSubmission($type, (string) $alanubeId, $fiscal->number);

        new ApplyFiscalAnswerAction(fiscalDocument: $fiscal, answer: $answer)->execute();

        return [
            'message' => 'e-CF result recorded.',
            'fiscal_document_id' => $fiscal->getId(),
            'document_type' => class_basename($fiscal->entity_type),
            'document_id' => $fiscal->entity_id,
            'encf' => $fiscal->number,
            'status' => $fiscal->refresh()->status->toEcfStatus()->value,
        ];
    }
}
