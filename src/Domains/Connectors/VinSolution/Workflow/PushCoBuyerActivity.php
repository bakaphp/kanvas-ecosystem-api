<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Guild\Customers\Actions\PushPeopleAction;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Workflow\KanvasActivity;

class PushCoBuyerActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(LeadParticipant $participant, Apps $app, array $params): array
    {
        $company = $participant->people->company;
        $lead = $participant->lead;
        $people = $participant->people;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }
        $vinCredential = ClientCredential::get(
            $lead->company,
            $lead->user,
            $lead->app
        );

        $pushPeopleToVin = new PushPeopleAction($people);
        $customer = $pushPeopleToVin->execute();

        $vinLeadId = $lead->get(CustomFieldEnum::LEADS->value);

        if (! $vinLeadId) {
            return [
                'error' => 'Lead ID found for  VinSolution',
            ];
        }

        try {
            $vinLead = Lead::getById($vinCredential->dealer, $vinCredential->user, $vinLeadId);
        } catch (Exception $e) {
            return [
                'error' => 'Lead not found in VinSolution',
            ];
        }

        $vinLead->coBuyerContact = $customer->id;
        $vinLead->update(
            $vinCredential->dealer,
            $vinCredential->user,
        );

        return [
            'message' => 'Co-buyer added successfully',
            'people' => $people->toArray(),
            'lead' => $lead->toArray(),
        ];
    }
}
