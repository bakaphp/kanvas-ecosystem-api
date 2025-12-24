<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Enums\SalesEventTypeEnum;
use Kanvas\Connectors\DealerSocket\Enums\SalesStatusEnum;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
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

        $people = People::from([
            'app' => $app,
            'company' => $lead['company'],
            'user' => $user,
            'firstname' => $firstname,
            'lastname' => $lead['customer']['lastName'] ?? '',
            'middlename' => $lead['customer']['middleName'] ?? null,
            'dob' => $lead['customer']['dateOfBirth'] ?? null,
            'contacts' => array_merge(
                array_map(
                    fn ($email) => [
                        'value' => $email['email'],
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 0,
                    ],
                    $lead['customer']['emails'] ?? []
                ),
                array_map(
                    fn ($phone) => [
                        'value' => $phone['number'] ?? '',
                        'contacts_types_id' => isset($phone['type']) && strtolower($phone['type']) === 'mobile'
                            ? ContactTypeEnum::CELLPHONE->value
                            : ContactTypeEnum::PHONE->value,
                        'weight' => isset($phone['type']) && ((int)$phone['type'] === 1 || strtolower($phone['type']) === 'mobile')
                            ? 100
                            : 0,
                    ],
                    $lead['customer']['phones'] ?? []
                )
            ),
            'address' => ! empty($lead['customer']['address'])
                ? [[
                    'address' => $lead['customer']['address']['street'] ?? '',
                    'city' => $lead['customer']['address']['city'] ?? '',
                    'state' => $lead['customer']['address']['state'] ?? '',
                    'country' => $country->name,
                    'country_id' => $country->id,
                    'zip' => $lead['customer']['address']['zipCode'] ?? '',
                ]]
                : [],
            'branch' => $lead['company']->defaultBranch,
            'custom_fields' => [
                CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value => $lead['customer']['entityId'],
            ],
        ]);

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
