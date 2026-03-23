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
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
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
                    'owner' => $lead->owner?->firstname,
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
        $filterResults = [];

        if ($customers && isset($customers['items'])) {
            foreach ($customers['items'] as $customer) {
                if ($customer['rank'] < 0.4) {
                    continue;
                }

                try {
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
                    $filterResults[$lead->id] = $lead->id;
                } catch (Throwable $th) {
                    //ignore the error

                    if (Str::contains($th->getMessage(), 'No Opportunities found') && $phone !== null && $phone !== '') {
                        /** @var Apps $app */
                        $app = $this->app;
                        $matchedByPhone = People::getAllByPhoneMatchingValue($phone, $this->company, $app);
                        $matchedByAnything = People::getAllByMatchingValue($phone, $this->company, $app);

                        $allMatchedPeople = $matchedByPhone->merge($matchedByAnything)->unique('id');

                        /** @var People $matchedPerson */
                        foreach ($allMatchedPeople as $matchedPerson) {
                            $internalClosedLead = LeadsRepository::getPeopleClosedLead($matchedPerson);

                            if (! $internalClosedLead) {
                                $activeLeadsQuery = LeadsRepository::getPeopleActiveLeads($matchedPerson);

                                if ($activeLeadsQuery->count() === 0) {
                                    continue;
                                }

                                /** @var ModelsLead $internalClosedLead */
                                $internalClosedLead = $activeLeadsQuery->first();
                                $internalClosedLead->close();
                            }

                            if (isset($filterResults[$internalClosedLead->id])) {
                                continue;
                            }

                            $nameRank = $this->calculateNameRank($firstname, $lastname, $matchedPerson);

                            $results[] = [
                                'id' => $internalClosedLead->id,
                                'uuid' => $internalClosedLead->uuid,
                                'people_id' => $internalClosedLead->people->id,
                                'firstname' => $internalClosedLead->people->firstname,
                                'middlename' => $internalClosedLead->people->middlename,
                                'lastname' => $internalClosedLead->people->lastname,
                                'email' => $internalClosedLead->people?->getEmails()->first()?->value,
                                'phone' => $internalClosedLead->people?->getAllPhones()->first()?->value,
                                'status' => strtolower($internalClosedLead->status()?->first()?->name ?? ''),
                                'lead_type' => $internalClosedLead->type?->name,
                                'owner' => $internalClosedLead->owner?->name,
                                'owner_id' => $internalClosedLead->leads_owner_id,
                                'custom_fields' => $internalClosedLead->getAllCustomFields(),
                                'rank' => $nameRank,
                            ];
                            $filterResults[$internalClosedLead->id] = $internalClosedLead->id;
                        }
                    }

                    continue;
                }
            }
        }

        return $results;
    }

    protected function calculateNameRank(
        ?string $eLeadFirstname,
        ?string $eLeadLastname,
        People $person
    ): float {
        $firstScore = 0.0;
        $lastScore = 0.0;

        $hasFirst = $eLeadFirstname !== null && $eLeadFirstname !== '' && (string) $person->firstname !== '';
        $hasLast = $eLeadLastname !== null && $eLeadLastname !== '' && (string) $person->lastname !== '';

        if ($hasFirst) {
            similar_text(
                strtolower(trim($eLeadFirstname)),
                strtolower(trim((string) $person->firstname)),
                $firstScore
            );
        }

        if ($hasLast) {
            similar_text(
                strtolower(trim($eLeadLastname)),
                strtolower(trim((string) $person->lastname)),
                $lastScore
            );
        }

        if ($hasFirst && $hasLast) {
            return (float) round(($firstScore * 0.4 + $lastScore * 0.6) / 100.0, 2);
        }

        if ($hasFirst) {
            return (float) round($firstScore / 100.0 * 0.5, 2);
        }

        if ($hasLast) {
            return (float) round($lastScore / 100.0 * 0.6, 2);
        }

        return 0.1;
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
