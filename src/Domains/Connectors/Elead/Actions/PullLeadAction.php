<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\Elead\Entities\Customer;
use Kanvas\Connectors\Elead\Entities\Lead;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Locations\Models\Countries;
use Throwable;

class PullLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user
    ) {
    }

    public function execute(array $request, ?ModelsLead $lead = null): array
    {
        $phone = $request['phone']['cell'] ?? $request['phone']['home'] ?? $request['phone']['work'] ?? null;
        //$emails = $request['emails'] ?? [];
        $email = $request['email'] ?? null;
        $dob = $request['birthday'] ?? null;
        $firstname = $request['firstname'] ?? null;
        $lastname = $request['lastname'] ?? null;
        //$personId = $request['personId'] ?? $request['entity_id'] ?? null;
        $entityId = $request['entity_id'] ?? null;
        // Check specifically in the provider array for is_active
        $filterActive = isset($request['is_active']);
        $isActiveValue = (int)($request['is_active'] ?? 0);
        //$filterOnlyActive = $filterActive && $isActiveValue === 1;

        /*   $people = People::getByCustomField(
              CustomFieldEnum::PERSON_ID->value,
              $personId,
              $this->company
          );

          if ($people !== null) {
              return [$people];
          } */

        $lead = $entityId !== null && $lead === null ? ModelsLead::getByCustomField(
            CustomFieldEnum::LEAD_ID->value,
            $entityId,
            $this->company
        ) : $lead;

        if ($entityId !== null && $lead !== null) {
            $lead->set(
                CustomFieldEnum::LEAD_ID->value,
                $entityId
            );

            new SyncLeadAction($lead)->execute();

            $this->setContactStatus($lead);

            return [
                [
                    'id' => $lead->id,
                    'uuid' => $lead->uuid,
                    'people_id' => $lead->people->id,
                    'firstname' => $lead->people->firstname,
                    'middlename' => $lead->people->middlename,
                    'lastname' => $lead->people->lastname,
                    'email' => $lead->people?->getEmails()->first()?->value,
                    'phone' => $lead->people?->getPhones()->first()?->value,
                    'status' => $lead->status()?->first()?->name ?? '',
                    'lead_type' => $lead->type?->name,
                    'owner' => $lead->owner?->name ,
                    'owner_id' => $lead->leads_owner_id,
                    'custom_fields' => $lead->getAllCustomFields(),
                    'recentlyCreated' => $lead->wasRecentlyCreated,
                ],
            ];
        }

        $eLeadCustomer = new Customer();
        $eLeadCustomer->company = $this->company;
        $eLeadCustomer->app = $this->app;

        //if the email is not complete , add the .com for the search
        if (is_string($email) && strpos($email, 'gmail.') !== false && strpos($email, 'gmail.com') === false) {
            $email .= 'com';
        }

        $params = [
            'phoneNumber' => $phone,
            'emailAddress' => $email,
            'firstName' => $firstname,
            'lastName' => $lastname,
        ];

        //if email doesn't have a @ , remove it
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            unset($params['emailAddress']);
        }

        $results = [];
        $customers = $eLeadCustomer->search($params);
        $country = Countries::getByCode('US');

        if ($customers && isset($customers['items'])) {
            foreach ($customers['items'] as $customer) {
                if ($customer['rank'] < 0.4) {
                    continue;
                }

                try {
                    $currentCustomer = People::getByCustomField(
                        CustomFieldEnum::CUSTOMER_ID->value,
                        $customer['id'],
                        $this->company
                    );

                    $eLead = Lead::getByCustomerId($this->app, $this->company, $customer['id']);
                    $eLead->customerId = $customer['id'];

                    $lead = new SyncLeadByThirdPartyCustomFieldAction(
                        DataTransferObjectLead::fromLeadEntity($eLead, $this->user)
                    )->execute();

                    $leadStatus = strtolower($lead->status()?->first()?->name ?? '');
                    $isActiveStatus = Str::contains($leadStatus, 'active');

                    // Improved filtering logic - more explicit
                    if ($filterActive) {
                        // is_active=1 means ONLY show active leads
                        if ($isActiveValue === 1 && ! $isActiveStatus) {
                            continue; // Skip non-active when requesting active
                        }

                        // is_active=0 means ONLY show inactive leads
                        if ($isActiveValue === 0 && $isActiveStatus) {
                            continue; // Skip active when requesting inactive
                        }
                    }
                    $this->setContactStatus($lead);
                    //$results[] = $lead;
                    $results[] = [
                        'id' => $lead->id,
                        'uuid' => $lead->uuid,
                        'people_id' => $lead->people->id,
                        'firstname' => $lead->people->firstname,
                        'middlename' => $lead->people->middlename,
                        'lastname' => $lead->people->lastname,
                        'email' => $lead->people?->getEmails()->first()?->value,
                        'phone' => $lead->people?->getAllPhones()->first()?->value,
                        'status' => $leadStatus,
                        'lead_type' => $lead->type?->name,
                        'owner' => $lead->owner?->name ,
                        'owner_id' => $lead->leads_owner_id,
                        'custom_fields' => $lead->getAllCustomFields(),
                        'rank' => $customer['rank'],
                        'recentlyCreated' => $lead->wasRecentlyCreated,
                    ];
                } catch (Throwable $th) {
                    //ignore the error

                    if (Str::contains($th->getMessage(), 'No Opportunities found') && ! empty($phone)) {
                        $searchForInternalCloseLeadByPhone = People::getByPhoneMatchingValue($phone, $this->company, $this->app);
                        $searchForInternalCloseLeadByAnything = People::getByMatchingValue($phone, $this->company, $this->app);

                        $searchForInternalCloseLead = $searchForInternalCloseLeadByPhone ?? $searchForInternalCloseLeadByAnything;

                        if ($searchForInternalCloseLead !== null) {
                            $internalClosedLeads = LeadsRepository::getPeopleClosedLead($searchForInternalCloseLead);

                            if ($currentCustomer === null) {
                                $currentCustomer = $searchForInternalCloseLead;
                            }

                            if (! $internalClosedLeads) {
                                $activeLeadsQuery = LeadsRepository::getPeopleActiveLeads($currentCustomer);

                                if ($activeLeadsQuery->count() === 0) {
                                    continue;
                                }

                                $internalClosedLeads = $activeLeadsQuery->first();
                                $activeLeadsQuery->update(['leads_status_id' => LeadStatus::getByName('close')->id]);
                            }

                            $results[] = [
                                'id' => $internalClosedLeads->id,
                                'uuid' => $internalClosedLeads->uuid,
                                'people_id' => $internalClosedLeads->people->id,
                                'firstname' => $internalClosedLeads->people->firstname,
                                'middlename' => $internalClosedLeads->people->middlename,
                                'lastname' => $internalClosedLeads->people->lastname,
                                'email' => $internalClosedLeads->people?->getEmails()->first()?->value,
                                'phone' => $internalClosedLeads->people?->getAllPhones()->first()?->value,
                                'status' => strtolower($internalClosedLeads->status()?->first()?->name ?? ''),
                                'lead_type' => $internalClosedLeads->type?->name,
                                'owner' => $internalClosedLeads->owner?->name ,
                                'owner_id' => $internalClosedLeads->leads_owner_id,
                                'custom_fields' => $internalClosedLeads->getAllCustomFields(),
                                'rank' => 1,
                            ];
                        }
                    }

                    continue;
                }
            }
        }

        return $results;
    }

    public function setContactStatus(ModelsLead $lead): void
    {
        $data = SalesActivities::getHistoryByOpportunityId(
            $lead->app,
            $lead->company,
            $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
        );

        if (! isset($data['items']) || ! is_array($data['items'])) {
            $lead->setContactStatus(LeadGroupStatusEnum::WAITING);
        }

        foreach ($data['items'] as $item) {
            if (! isset($item['createdBy'])) {
                continue;
            }

            $createdBy = $item['createdBy'];

            $createdByLower = strtolower($createdBy);

            $isSystem =
                $createdByLower === 'system' ||
                str_contains($createdByLower, 'fortellis');

            if (! $isSystem) {
                $lead->setContactStatus(LeadGroupStatusEnum::CONTACTED);

                return ;
            }
        }

        $lead->setContactStatus(LeadGroupStatusEnum::WAITING);
    }
}
