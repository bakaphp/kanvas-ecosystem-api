<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Elead\Entities\Lead as LeadEntity;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Locations\Models\Countries;
use Kanvas\Users\Models\UserConfig;

class Lead extends DataTransferObjectLead
{
    public function fromLeadEntity(LeadEntity $lead, UserInterface $user): self
    {
        $customer = $lead->customer();

        $firstname = $customer->isBusiness ? $customer->businessName : $customer->firstName;
        $country = Countries::getByCode('US');
        $people = People::from([
            'app' => $lead->app,
            'company' => $lead->company,
            'user' => $user,
            'firstname' => $firstname,
            'lastname' => $customer->lastName ?? null,
            'dob' => $customer->birthday ?? null,
            'contacts' => array_merge(
                array_map(
                    fn ($email) => [
                        'value' => $email['address'],
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 0,
                    ],
                    $customer->emails
                ),
                array_map(
                    fn ($phone) => [
                        'value' => $phone['number'],
                        'contacts_types_id' => ContactTypeEnum::PHONE->value,
                        'weight' => 0,
                    ],
                    $customer->phones
                )
            ),
            'address' => [
                array_map(
                    fn ($address) => [
                        'address' => $address['addressLine1'] ?? '',
                        'city' => $address['city'] ?? '',
                        'state' => $address['state'] ?? '',
                        'country' => $country->name,
                        'country_id' => $country->id,
                        'zip' => $address['zip'] ?? '',
                    ],
                    $customer->addresses ?? []
                ),
            ],
            'branch' => $lead->company->defaultBranch,
            'custom_fields' => [
                CustomFieldEnum::CUSTOMER_ID => $customer->id,
            ],
        ]);

        $eLeadOwnerId = null;

        if (isset($lead->salesTeam[0])) {
            $eLeadOwnerId = UserConfig::where('name', CustomFieldEnum::getUserKey($lead->company))
            ->where('value', $lead->salesTeam[0]['id'])
            ->first();
        }

        $source = LeadSource::where('name', $lead->source)
            ->fromApp($lead->app)
            ->fromCompany($lead->company)
            ->first();

        $leadType = LeadType::where('name', $lead->upType)
            ->fromCompany($lead->company)
            ->fromApp($lead->app)
            ->first();

        $status = LeadStatus::where('name', $lead->status)
            ->first();

        return self::from([
            'app' => $lead->app,
            'company' => $lead->company,
            'user' => $user,
            'title' => $people->firstname . ' ' . $people->lastname . ' Opp',
            'pipeline_stage_id' => 0,
            'people' => $people,
            'type_id' => $leadType?->id,
            'source_id' => $source?->id,
            'status_id' => $status?->id,
            'leads_owner_id' => $eLeadOwnerId?->user_id,
            'custom_fields' => [
                CustomFieldEnum::LEAD_ID => $lead->id,
            ],
        ]);
    }
}
