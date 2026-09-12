<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\Elead\Entities\Customer;
use Kanvas\Connectors\Elead\Entities\Lead;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Support\EleadDebounce;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleMatchScore;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadPullResult;
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
        $cellPhone = $request['phone']['cell'] ?? null;
        $homePhone = $request['phone']['home'] ?? null;
        $workPhone = $request['phone']['work'] ?? null;
        $phone = $cellPhone ?? $homePhone ?? $workPhone;

        $email = $request['email'] ?? null;
        $secondEmail = $request['emails'][1]['value'] ?? $request['emails'][1]['address'] ?? null;
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

            try {
                $debounce = new EleadDebounce($this->app, $this->company);
                $isRapidCall = $debounce->shouldSkip('pull_lead', (string) $entityId, 30);

                $eLead = new SyncLeadAction($lead, fresh: ! $isRapidCall)->execute();
                $this->setContactStatus($lead, $eLead->subStatus);
            } catch (ClientException $e) {
                // If the opportunity doesn't exist in Elead (404), close the lead and return it
                if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                    $lead->close();
                }
            }

            $nameRank = PeopleMatchScore::for(
                $lead->people,
                firstname: $firstname,
                lastname: $lastname,
                phones: [$cellPhone, $homePhone, $workPhone],
                emails: [$email, $secondEmail],
            )->value;

            return [
                LeadPullResult::for($lead, $nameRank)->toArray(),
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
        $filterResults = [];

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

                    $eLead = Lead::getByCustomerId(
                        $this->app,
                        $this->company,
                        $customer['id']
                    );
                    $eLead->customerId = $customer['id'];

                    $lead = new SyncLeadByThirdPartyCustomFieldAction(
                        DataTransferObjectLead::fromLeadEntity(
                            $eLead,
                            $this->user
                        )
                    )->execute();

                    if (isset($filterResults[$lead->id])) {
                        continue; // Skip if this lead has already been processed
                    }

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
                    $this->setContactStatus($lead, $eLead->subStatus);
                    //$results[] = $lead;

                    $results[] = LeadPullResult::for($lead, (float) $customer['rank'])->toArray();
                    $filterResults[$lead->id] = $lead->id;
                } catch (Throwable $th) {
                    //ignore the error

                    $hasPhone = $phone !== null && $phone !== '';
                    $hasEmail = $email !== null && $email !== '';

                    if (Str::contains($th->getMessage(), 'No Opportunities found') && ($hasPhone || $hasEmail)) {
                        /** @var Apps $app */
                        $app = $this->app;
                        $allMatchedPeople = collect();

                        if ($hasPhone) {
                            $allMatchedPeople = $allMatchedPeople
                                ->merge(People::getAllByPhoneMatchingValue($phone, $this->company, $app))
                                ->merge(People::getAllByMatchingValue($phone, $this->company, $app));
                        }

                        if ($email !== null && $email !== '') {
                            $allMatchedPeople = $allMatchedPeople
                                ->merge(People::getAllByMatchingValue($email, $this->company, $app));
                        }

                        $allMatchedPeople = $allMatchedPeople->unique('id');

                        /** @var People $matchedPerson */
                        foreach ($allMatchedPeople as $matchedPerson) {
                            $nameRank = PeopleMatchScore::for(
                                $matchedPerson,
                                firstname: $firstname,
                                lastname: $lastname,
                                phones: [$cellPhone, $homePhone, $workPhone],
                                emails: [$email, $secondEmail],
                            )->value;
                            $closedLeads = LeadsRepository::getPeopleClosedLeads($matchedPerson)->get();

                            if ($closedLeads->isEmpty()) {
                                $activeLeads = LeadsRepository::getPeopleActiveLeads($matchedPerson)->get();

                                if ($activeLeads->isEmpty()) {
                                    continue;
                                }

                                /** @var ModelsLead $activeLead */
                                foreach ($activeLeads as $activeLead) {
                                    $activeLead->close();
                                    $closedLeads->push($activeLead);
                                }
                            }

                            /** @var ModelsLead $closedLead */
                            foreach ($closedLeads as $closedLead) {
                                if (isset($filterResults[$closedLead->id])) {
                                    continue;
                                }

                                $results[] = LeadPullResult::for($closedLead, $nameRank)->toArray();
                                $filterResults[$closedLead->id] = $closedLead->id;
                            }
                        }
                    }

                    continue;
                }
            }
        }

        usort($results, fn (array $a, array $b) => ($b['rank'] ?? 0) <=> ($a['rank'] ?? 0));

        return $results;
    }

    public function setContactStatus(ModelsLead $lead, string $status): void
    {
        if ($lead->hasBeenContacted()) {
            return;
        }
        $hasReachOut = SalesActivities::hasSalesAgentReachedOut(
            $lead->app,
            $lead->company,
            $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
        );

        if ($hasReachOut) {
            $lead->setContactStatus(LeadGroupStatusEnum::CONTACTED);
        } else {
            $lead->setContactStatus(LeadGroupStatusEnum::WAITING);
        }
    }
}
