<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\DataCollection;

class PullPeopleAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $payload,
        protected string $salesforceId,
    ) {
    }

    public function execute(): People
    {
        $branch = $this->company->defaultBranch ?? $this->company->user->getCurrentCompany()->branch;
        $firstName = (string) ($this->payload['FirstName'] ?? '');
        $lastName = (string) ($this->payload['LastName'] ?? 'Unknown');

        $contacts = [];
        if (! empty($this->payload['Email'])) {
            $contacts[] = ['value' => $this->payload['Email'], 'contacts_types_id' => 1, 'weight' => 0];
        }
        if (! empty($this->payload['Phone'])) {
            $contacts[] = ['value' => $this->payload['Phone'], 'contacts_types_id' => 2, 'weight' => 0];
        }

        $peopleData = new PeopleData(
            app: $this->app,
            branch: $branch,
            user: $this->company->user,
            firstname: $firstName !== '' ? $firstName : $lastName,
            contacts: Contact::collect($contacts, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $lastName,
            custom_fields: [
                CustomFieldEnum::SALESFORCE_CONTACT_ID->value => $this->salesforceId,
            ],
            runWorkflow: false,
        );

        $people = new SyncPeopleByThirdPartyCustomFieldAction($peopleData)->execute();

        if (! empty($this->payload['AccountId'])) {
            /** @var Organization|null $organization */
            $organization = Organization::getByCustomFieldTransactionSafe(
                CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value,
                (string) $this->payload['AccountId'],
                $this->company,
            );

            // Link via the pivot directly instead of the People DTO's `organization` name field —
            // that path funnels through CreateOrganizationAction, which always fires a workflow
            // and would risk the same outbound-sync loop the runWorkflow:false above guards against.
            $organization?->addPeople($people);
        }

        return $people;
    }
}
