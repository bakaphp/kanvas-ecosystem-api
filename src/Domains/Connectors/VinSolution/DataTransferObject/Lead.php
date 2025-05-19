<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Leads\Source;
use Kanvas\Connectors\VinSolution\Leads\Types;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource as DataTransferObjectLeadSource;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Locations\Models\Countries;
use Kanvas\Users\Models\UserConfig;

class Lead extends DataTransferObjectLead
{
    public static function fromVinLeadArray(
        array $data,
        Dealer $dealer,
        User $dealerUser,
        AppInterface $app,
        Companies $company,
        UserInterface $user
    ): self {
        $customer = Contact::getById($dealer, $dealerUser, $data['CustomerId']);
        $country = Countries::getByCode('US');
        $people = People::fromContact($customer, $app, $company, $user);

        /*         $eLeadOwnerId = null;s

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
                    ->first(); */

        $leadOwner = self::getSalesRep($customer->dealerTeam);

        if ($leadOwner['UserId']) {
            $userConfig = UserConfig::where('name', 'LIKE', CustomFieldEnum::USER->value . '_' . $company->getId() . '%')
                ->where('value', $leadOwner['UserId'])
                ->orderBy('users_id', 'DESC')
                ->first();

            if ($userConfig) {
                $leadOwnerId = $userConfig->users_id;
            }
        }

        //add followers @todo

        $source = Source::getById(
            $dealer,
            $dealerUser,
            (int) $data['LeadSource']
        );

        $localLeadSource = new CreateLeadSourceAction(
            new DataTransferObjectLeadSource(
                app: $app,
                company: $company,
                leads_types_id: null,
                name: $source->name,
                is_active: true,
                description: $data['LeadSource']
            )
        )->execute();
        $localLeadSource->set(CustomFieldEnum::LEADS_SOURCE_ID->value, (int) $data['LeadSource']);

        $vinLeadsType = self::getLeadsType($company);
        $vinLeadsTypeId = ! isset($data['newLeadType']) ? ($vinLeadsType[$data['LeadType'] - 1] ?? 'INTERNET') : $data['newLeadType'];

        $localLeadType = LeadType::fromApp($app)->fromCompany($company)->where('name', strtoupper($vinLeadsTypeId))->first();

        $leadStatusId = $data['LeadStatusType']; //so we can look for it on the api response array

        $leadStatus = LeadStatus::firstOrCreate([
            'name' => $leadStatusId,
        ]);

        return self::from([
            'app' => $app,
            'branch' => $company->defaultBranch,
            'user' => $user,
            'title' => $people->firstname . ' ' . $people->lastname . ' Opp',
            'pipeline_stage_id' => 0,
            'people' => $people,
            'type_id' => $localLeadType?->id ?? 0,
            'source_id' => $localLeadSource?->id ?? 0,
            'status_id' => $leadStatus->id,
            'leads_owner_id' => $leadOwnerId ?? $user->getId(),
            'custom_fields' => [
                CustomFieldEnum::LEADS->value => $data['LeadId'],
            ],
        ]);
    }

    public static function getSalesRep(array $dealerTeam): array
    {
        foreach ($dealerTeam as $teamMember) {
            if ($teamMember['RoleName'] === 'Sales Rep') {
                return $teamMember;
            }
        }

        return [
            'UserId' => null,
            'FullName' => null,
            'RoleName' => null,
        ]; // If no "Sales Rep" is found in the array
    }

    /**
     * Get and cache leads type.
     */
    public static function getLeadsType(Companies $company): array
    {
        $key = 'vinsolutions-leads-type' . $company->getId();
        $cache = Cache::store('redis');

        return $cache->remember($key, 3500, function () {
            return Types::getAll();
        });
    }
}
