<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Enums\SalesEventTypeEnum;
use Kanvas\Connectors\DealerSocket\Enums\SalesStatusEnum;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Locations\Models\Countries;
use Kanvas\Users\Models\UsersAssociatedApps;
use Override;

class Lead extends DataTransferObjectLead
{
    #[Override]
    public static function fromMultiple(
        UserInterface $user,
        AppInterface $app,
        array $request,
    ): self {
        $lead = $request;
        $firstname = $lead['customer']['firstName'];
        $country = Countries::getByCode('US');
        $leadData = ! empty($lead['events']) ? end($lead['events']) : [];

        $people = People::fromMultiple(
            $user,
            $app,
            $lead['company'],
            $lead['customer']
        );

        $eLeadOwnerId = null;

        if (isset($leadData['personAssigned'])) {
            $eLeadOwnerId = UsersAssociatedApps::where('apps_id', $app->getId())
                ->where('companies_id', $lead['company']->getId())
                ->where('displayname', $leadData['personAssigned'])
                ->first();
        }

        $source = LeadSource::where('name', 'DealerSocket')
            ->fromApp($app)
            ->fromCompany($lead['company'])
            ->first();

        $eventType = SalesEventTypeEnum::fromId((int)($leadData['eventType'] ?? 100050));
        $leadType = LeadType::where('name', $eventType?->label())
            ->fromCompany($lead['company'])
            ->fromApp($app)
            ->first();

        $statusEnum = SalesStatusEnum::fromId((int)($leadData['status'] ?? 220));
        $status = LeadStatus::where('name', $statusEnum?->label())
            ->first();

        $customFields = [
             CustomFieldEnum::DEALER_SOCKET_LEAD_ID->value => $leadData['eventId'],
         ];

        //vehicle of interest
        if (isset($leadData['vehicle']) && is_array($leadData['vehicle']) && ! empty($leadData['vehicle'])) {
            $milage = $leadData['vehicle']['currentMileage'] ?? 0;

            $customFields['vehicle_of_interest'] = [
                'year' => $leadData['vehicle']['year'] ?? null,
                'make' => $leadData['vehicle']['make'] ?? null,
                'model' => $leadData['vehicle']['model'] ?? null,
                'trim' => null,
                'color' => null,
                'isNew' => (int) $milage <= 100 ? true : false,
                'vin' => $leadData['vehicle']['vin'] ?? null,
                'stock_number' => $leadData['vehicle']['stockNumber'] ?? null,
                'mileage' => $milage,
            ];
        }

        return self::from([
            'app' => $app,
            'branch' => $lead['company']->defaultBranch,
            'user' => $user,
            'title' => $people->firstname . ' ' . $people->lastname . ' Opp',
            'pipeline_stage_id' => 0,
            'people' => $people,
            'type_id' => $leadType?->id ?? 0,
            'source_id' => $source?->id ?? 0,
            'status_id' => $status?->id ?? 0,
            'leads_owner_id' => $eLeadOwnerId?->user_id ?? 0,
            'custom_fields' => $customFields,
        ]);
    }
}
