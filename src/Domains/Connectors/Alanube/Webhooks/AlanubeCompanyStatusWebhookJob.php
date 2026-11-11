<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Webhooks;

use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

/**
 * The company's DGII status changed at Alanube (e.g. it stopped being enabled to issue). Stored on the company
 * for the readiness check; the ledger event fires only on an actual change, so a re-delivery is a no-op.
 */
#[WorkflowAction(
    name: 'Alanube DGII Company Status Webhook',
    description: 'Receiver for Alanube general.governmentStatusChanged: stores the company DGII status for the '
        . 'e-CF readiness check and records a ledger event when it changes. Inbound only.',
    integration: IntegrationsEnum::ALANUBE,
)]
class AlanubeCompanyStatusWebhookJob extends AlanubeWebhookJob
{
    public const string LEDGER_EVENT = 'scribe.ecf.provider_company_status_changed';

    #[Override]
    public function execute(): array
    {
        $status = $this->payloadValue('status')
            ?? $this->payloadValue('governmentStatus')
            ?? $this->payloadValue('dgiiStatus');

        if ($status === null) {
            return ['message' => 'No status in the payload; nothing to do.', 'keys' => array_keys($this->payload())];
        }

        $company = $this->company();
        $previous = $company->get(ConfigurationEnum::GOVERNMENT_STATUS->value);

        if ($previous === $status) {
            return ['message' => 'Status unchanged.', 'status' => $status];
        }

        $company->set(ConfigurationEnum::GOVERNMENT_STATUS->value, $status);

        $this->recordLedgerEvent(
            eventType: self::LEDGER_EVENT,
            correlationId: null,
            payload: [
                'provider' => EcfProviderEnum::ALANUBE->value,
                'previous_status' => $previous,
                'status' => $status,
            ],
            entity: $company,
        );

        return ['message' => 'Company DGII status updated.', 'previous' => $previous, 'status' => $status];
    }
}
