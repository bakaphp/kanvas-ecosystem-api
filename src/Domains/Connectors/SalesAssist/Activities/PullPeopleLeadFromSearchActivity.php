<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\DataTransferObject\Lead as EleadLeadData;
use Kanvas\Connectors\Elead\Entities\Customer as EleadCustomer;
use Kanvas\Connectors\Elead\Entities\Lead as EleadLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Exceptions\ELeadException;
use Kanvas\Connectors\VinSolution\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

#[WorkflowAction(
    name: 'SalesAssist Search CRM For Leads',
    description: 'Searches the connected CRM for customers matching an email, phone or name and pulls what it '
        . 'finds into Kanvas as leads. A search-and-import step — it can create several records from '
        . 'one run, so it is not a per-record action.',
    integration: IntegrationsEnum::SALESASSIST,
)]
class PullPeopleLeadFromSearchActivity extends KanvasActivity
{
    protected ?Companies $company = null;
    protected ?Apps $app = null;

    public function execute(Apps $apps, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($apps);
        $company = $params['company'];
        $this->company = $company;
        $this->app = $app;

        $searchText = $params['search'] ?? $params['email'] ?? null;
        $user = $params['user'] ?? null;

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;

        if (empty($searchText)) {
            return [];
        }

        try {
            if ($isElead) {
                return $this->searchEleadLeads($searchText, $user);
            } elseif ($isVinSolutions) {
                return $this->searchVinSolutionLeads($searchText, $user);
            }
        } catch (ClientException $e) {
            // its its not a 403 error we report it
            if ($e->getResponse()?->getStatusCode() !== 403) {
                report($e);
            }
        }

        return [];
    }

    /**
     * Search for leads in Elead system by customer information.
     */
    protected function searchEleadLeads(string $searchText, UserInterface $user): array
    {
        $criteria = $this->buildEleadSearchCriteria($searchText);

        if (empty($criteria)) {
            return [];
        }

        $eLeadCustomer = new EleadCustomer();
        $eLeadCustomer->app = $this->app;
        $eLeadCustomer->company = $this->company;

        $customers = $eLeadCustomer->search($criteria);

        if (empty($customers['items'])) {
            return [];
        }

        $results = [];

        foreach ($customers['items'] as $customer) {
            if (empty($customer['id'])) {
                continue;
            }

            try {
                $eLead = EleadLead::getByCustomerId($this->app, $this->company, (string) $customer['id']);
                // getByCustomerId hydrates from the opportunity item; the customer id isn't on it,
                // so set it explicitly — fromLeadEntity re-fetches the customer through it.
                $eLead->customerId = (string) $customer['id'];

                if (! $eLead->isActive()) {
                    continue;
                }

                $leadDto = EleadLeadData::fromLeadEntity($eLead, $user);
                $lead = new SyncLeadByThirdPartyCustomFieldAction($leadDto)->execute();
                $lead->searchable();

                $results[] = [
                    'id' => $lead->id,
                    'uuid' => $lead->uuid,
                    'people_id' => $lead->people->id,
                    'firstname' => $lead->people->firstname,
                    'middlename' => $lead->people->middlename,
                    'lastname' => $lead->people->lastname,
                    'email' => $lead->people?->getEmails()->first()?->value,
                    'phone' => $lead->people?->getPhones()->first()?->value,
                    'status' => $lead->status()?->first()?->name,
                    'lead_type' => $lead->type?->name,
                    'owner' => $lead->owner?->name,
                    'owner_id' => $lead->leads_owner_id,
                    'custom_fields' => $lead->getAllCustomFields(),
                    'updated_at' => $lead->updated_at,
                    'rank' => 1,
                ];
            } catch (ELeadException $e) {
                // No opportunity for this customer — skip, nothing to sync.
                continue;
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (! empty($results)) {
            usort($results, fn ($a, $b) => $b['updated_at'] <=> $a['updated_at']);
        }

        return $results;
    }

    /**
     * Build eLead customer search criteria, narrowing to the most precise axis:
     * an email or phone match when the text looks like one, otherwise name parts.
     */
    protected function buildEleadSearchCriteria(string $searchText): array
    {
        if (filter_var($searchText, FILTER_VALIDATE_EMAIL)) {
            return ['emailAddress' => $searchText];
        }

        $digits = preg_replace('/\D/', '', $searchText);
        if (strlen($digits) >= 7) {
            return ['phoneNumber' => $digits];
        }

        $name = preg_split('/\s+/', trim($searchText));

        return [
            'firstName' => $name[0] ?? '',
            'lastName' => $name[1] ?? '',
        ];
    }

    /**
     * Search for leads in VinSolution system by contact information.
     */
    protected function searchVinSolutionLeads(string $searchText, UserInterface $user): array
    {
        $dealerId = $this->company->get(EnumsCustomFieldEnum::COMPANY->value);
        if (empty($dealerId)) {
            return [];
        }

        try {
            $vinCompany = Dealer::getById((int) $dealerId, $this->app);
        } catch (ModelNotFoundException $e) {
            // Company points at a dealer this app key can't see — nothing to search.
            return [];
        }

        $vinUserId = $user->get(ConfigurationEnum::getUserKey($this->company, $user));

        if (! $vinUserId) {
            return [];
        }

        $vinUser = Dealer::getUser($vinCompany, $vinUserId, $this->app);
        $results = [];

        // Search for contacts first using the search text
        $contacts = Contact::getAll(
            $vinCompany,
            $vinUser,
            $searchText,
            [
                'email' => filter_var($searchText, FILTER_VALIDATE_EMAIL) ? $searchText : null,
                'phone' => is_numeric(preg_replace('/\D/', '', $searchText)) ? $searchText : null,
            ]
        );

        if (empty($contacts)) {
            return $results;
        }

        // For each contact found, search for their leads
        foreach ($contacts as $contact) {
            if (! isset($contact['ContactId'])) {
                continue;
            }

            try {
                // Search for leads by CustomerId (ContactId)
                $params = [
                    'pageNumber' => 1,
                    'pageSize' => 5,
                    'CustomerId' => $contact['ContactId'],
                ];

                $leads = Lead::getAll($vinCompany, $vinUser, $params);

                if (empty($leads['Leads'])) {
                    continue;
                }

                // Process each lead found
                foreach ($leads['Leads'] as $vinLead) {
                    try {
                        $leadDto = DataTransferObjectLead::fromVinLeadArray(
                            $vinLead,
                            $vinCompany,
                            $vinUser,
                            $this->app,
                            $this->company,
                            $user
                        );

                        $lead = new SyncLeadByThirdPartyCustomFieldAction($leadDto)->execute();
                        $lead->searchable();

                        $results[] = [
                            'id' => $lead->id,
                            'uuid' => $lead->uuid,
                            'people_id' => $lead->people->id,
                            'firstname' => $lead->people->firstname,
                            'middlename' => $lead->people->middlename,
                            'lastname' => $lead->people->lastname,
                            'email' => $lead->people?->getEmails()->first()?->value,
                            'phone' => $lead->people?->getPhones()->first()?->value,
                            'status' => $lead->status()?->first()?->name,
                            'lead_type' => $lead->type?->name,
                            'owner' => $lead->owner?->name,
                            'owner_id' => $lead->leads_owner_id,
                            'custom_fields' => $lead->getAllCustomFields(),
                            'updated_at' => $lead->updated_at,
                            'rank' => 1,
                        ];
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            } catch (ClientException $e) {
                if ($e->getResponse()?->getStatusCode() === 403) {
                    continue;
                }
                report($e);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Sort results by updated_at date (most recent first)
        if (! empty($results)) {
            usort($results, function ($a, $b) {
                return $b['updated_at'] <=> $a['updated_at'];
            });
        }

        return $results;
    }
}
