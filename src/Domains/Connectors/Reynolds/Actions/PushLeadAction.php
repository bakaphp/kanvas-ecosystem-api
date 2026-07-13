<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Kanvas\Connectors\Reynolds\Client;
use Kanvas\Connectors\Reynolds\DataTransferObject\Lead as LeadData;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Enums\TransactionCodeEnum;
use Kanvas\Connectors\Reynolds\Services\ApplicationAreaBuilder;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;

class PushLeadAction
{
    private const ROOT_ELEMENT = 'rey_SalesAssistCRMInsertSalesLead';

    public function __construct(
        protected LeadModel $lead
    ) {
    }

    public function execute(): array
    {
        // Upsert semantics: if the lead already carries a REYNOLDS_PROSPECT_ID
        // it exists in R&R and can only be Updated (USL) — re-sending an ISL
        // for the same prospect makes R&R reject the request.
        if ($this->lead->get(CustomFieldEnum::PROSPECT_ID->value)) {
            return new UpdateLeadAction($this->lead)->execute();
        }

        $client = new Client($this->lead->app, $this->lead->company);
        $payload = $this->buildPayload($client);

        $response = $client->processMessage(self::ROOT_ELEMENT, $payload);

        // R&R can return <ProspectId/> (empty self-closing) which the XML parser
        // resolves to an empty array rather than null. Normalize to ?string.
        $prospectIdRaw = $response['TransStatus']['ProspectId'] ?? null;
        $prospectId = is_scalar($prospectIdRaw) ? (string) $prospectIdRaw : null;

        if ($prospectId !== null) {
            $this->lead->people->set(CustomFieldEnum::NAME_REC_ID->value, $prospectId);
            $this->lead->set(CustomFieldEnum::PROSPECT_ID->value, $prospectId);
        }

        return [
            'prospect_id' => $prospectId,
            'response' => $response,
        ];
    }

    private function buildPayload(Client $client): array
    {
        $leadData = LeadData::fromLead($this->lead);
        $peopleSection = new SyncPeopleAction($this->lead->people)->execute();

        // DesiredVehicle and PotentialTrade are intentionally not pushed back to
        // Reynolds — see LeadDTO docblock. Pull keeps the inbound data on the
        // lead as custom fields but we never send it up.
        $record = array_filter([
            'Prospect' => $leadData->toProspect(),
        ], fn ($v) => ! empty($v));

        $record += $peopleSection;

        return [
            'ApplicationArea' => ApplicationAreaBuilder::build(
                $client,
                TransactionCodeEnum::INSERT_SALES_LEAD,
                'I'
            ),
            'Record' => $record,
        ];
    }
}
