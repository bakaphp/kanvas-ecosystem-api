<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Kanvas\Connectors\Reynolds\Client;
use Kanvas\Connectors\Reynolds\DataTransferObject\Lead as LeadData;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Enums\TransactionCodeEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Connectors\Reynolds\Services\ApplicationAreaBuilder;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;

/**
 * Reynolds Update Sales Lead (USL) — Customer sub-flow.
 *
 * Sends the same Customer/Address/Phones/Email/Consent projection that
 * PushLeadAction (ISL) builds, but wrapped in the Update root element
 * and carrying the existing ProspectId so R&R knows which prospect to
 * mutate instead of creating a new one.
 */
class UpdateLeadAction
{
    private const ROOT_ELEMENT = 'rey_SalesAssistCRMUpdateSalesLead';

    public function __construct(
        protected LeadModel $lead
    ) {
    }

    public function execute(): array
    {
        $client = new Client($this->lead->app, $this->lead->company);

        $prospectId = $this->lead->get(CustomFieldEnum::PROSPECT_ID->value);
        if (empty($prospectId)) {
            throw new ReynoldsException(
                'UpdateLeadAction requires REYNOLDS_PROSPECT_ID on the lead — call PushLeadAction (ISL) first.'
            );
        }

        $payload = $this->buildPayload($client, (string) $prospectId);

        $response = $client->processMessage(self::ROOT_ELEMENT, $payload);

        return [
            'prospect_id' => (string) $prospectId,
            'response' => $response,
        ];
    }

    private function buildPayload(Client $client, string $prospectId): array
    {
        $leadData = LeadData::fromLead($this->lead);
        $peopleSection = new SyncPeopleAction($this->lead->people)->execute();

        // USL puts ProspectId in a separate <Identifier> block inside Record
        // (mirrors the shape used by inbound LDU envelopes and the working
        // USL Note sub-flow — see AddNoteToLeadAction).
        $record = array_filter([
            'Identifier' => ['ProspectId' => $prospectId],
            'Prospect' => $leadData->toProspect(),
        ], fn ($v) => ! empty($v));

        $record += $peopleSection;

        return [
            'ApplicationArea' => ApplicationAreaBuilder::build(
                $client,
                TransactionCodeEnum::UPDATE_SALES_LEAD,
                'I'
            ),
            'Record' => $record,
        ];
    }
}
