<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushParticipantActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(LeadParticipant $participant, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $people = $participant->people;
        $lead = $participant->lead;

        if (! $people->company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            integrationOperation: function ($participant, $app, $integrationCompany, $additionalParams) use ($lead, $people) {
                //id, crmid, firstname, middlename, lastname, email and phone

                $customerData = [
                    'id' => $people->getId(),
                    'crmid' => $people->get(CustomFieldEnum::CUSTOMER_ID->value),
                    'firstname' => $people->firstname,
                    'middlename' => $people->middlename,
                    'lastname' => $people->lastname,
                    'email' => $people->getEmails()->count() ? $people->getEmails()->first()->value : null,
                    'phone' => $people->getPhones()->count() ? $people->getPhones()->first()->value : null,
                ];
                $lead->set('cobuyer-customer-imported', $customerData);

                return [
                    'message' => 'Participant synced successfully',
                    'customerData' => $customerData,
                ];
            },
            company: $people->company,
        );
    }
}
