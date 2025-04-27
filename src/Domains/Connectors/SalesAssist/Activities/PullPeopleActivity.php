<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\Elead\Entities\Customer;
use Kanvas\Connectors\Elead\Entities\Lead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class PullPeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?Companies $company = null;
    protected ?Apps $app = null;

    #[Override]
    /**
     * $entity <People>
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $isSync = $entity->id === 0;
        $company = Companies::getById($entity->company_id);
        $this->company = $company;
        $this->app = $app;
        $peopleId = $params['entity_id'] ?? null;

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )

        if ($isElead) {
            return $this->pullEleadPeople($params);
        }

        return [];
    }

    private function pullEleadPeople(array $request): array
    {
        $phones = $request['phones'] ?? [];
        $emails = $request['emails'] ?? [];
        $email = $emails[0] ?? null;
        $dob = $request['birthday'] ?? null;
        $firstname = $request['firstname'] ?? null;
        $lastname = $request['lastname'] ?? null;
        $personId = $request['personId'];
        $phone = $phones[0] ?? null;

        $people = People::getByCustomField(
            Str::isUuid($personId) ? CustomFieldEnum::CUSTOMER_ID->value : CustomFieldEnum::PERSON_ID->value,
            $personId,
            $this->company
        );

        if ($people !== null) {
            return [$people];
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

        if ($customers && isset($customers['items'])) {
            foreach ($customers['items'] as $customer) {
                if ($customer['rank'] < 0.4) {
                    continue;
                }

                try {
                    //cache for 60 to 120 seconds
                    $eLead = Lead::getByCustomerId($this->app, $this->company, $customer['id']);
                    $eLead->customerId = $customer['id'];
                    $lead = new SyncLeadByThirdPartyCustomFieldAction(
                        DataTransferObjectLead::fromLeadEntity($eLead, $this->company->user)
                    )->execute();
                    //$syncLeadByCustomFieldAction = new SyncLeadByCustomFieldAction($company);
                    //$lead = $syncLeadByCustomFieldAction->execute(LeadData::createFromLead($eLead));

                    //$people = $lead->people;

                    //$leadData = (new DocumentsLeads())->setDataModel($lead);
                    //$leadData->data['rank'] = $customer['rank']; //eleads search rank
                    //$leadData->data['rank'] = $customer['rank'] < 0.6 ? 0.68 : $customer['rank']; //eleads search rank
                    // $leadData->data['rank'] = $customer['rank'];
                    // $leadData->data['status_name'] = 'Active';
                    // $searchResults[] = $leadData;
                    $results[] = $lead->people;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        return $results;
    }
}
