<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

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
     * Search for leads in Elead system.
     */
    protected function searchEleadLeads(string $searchText, UserInterface $user): array
    {
        // Implementation would be similar to Elead's IndexLeadsBySearch
        // For now, return empty array as the focus is on VinSolution
        return [];
    }

    /**
     * Search for leads in VinSolution system by contact information.
     */
    protected function searchVinSolutionLeads(string $searchText, UserInterface $user): array
    {
        $vinCompany = Dealer::getById($this->company->get(EnumsCustomFieldEnum::COMPANY->value), $this->app);
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
