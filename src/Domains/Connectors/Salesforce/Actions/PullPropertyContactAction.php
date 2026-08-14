<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Inventory\Products\Models\Products;

class PullPropertyContactAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Products $product,
        protected array $payload,
        protected string $salesforceId,
    ) {
    }

    public function execute(): ?People
    {
        $contactName = trim((string) ($this->payload['Contact_Name__c'] ?? ''));
        if ($contactName === '') {
            return null;
        }

        $people = People::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_LOCATION_CONTACT_ID->value,
            $this->salesforceId,
            $this->company,
        );

        if ($people === null) {
            $people = new People();
            $people->apps_id = $this->app->getId();
            $people->companies_id = $this->company->getId();
            $people->users_id = $this->company->user->getId();
        }

        [$firstname, $lastname] = $this->splitName($contactName);
        $people->firstname = $firstname;
        $people->lastname = $lastname;
        $people->name = $contactName;
        $people->disableWorkflows();
        $people->saveOrFail();
        $people->set(CustomFieldEnum::SALESFORCE_LOCATION_CONTACT_ID->value, $this->salesforceId);

        $email = (string) ($this->payload['Contact_Email__c'] ?? '');
        if ($email !== '') {
            $this->syncContact($people, ContactTypeEnum::EMAIL->value, $email);
        }

        $phone = (string) ($this->payload['Contact_Phone__c'] ?? $this->payload['Contact_Mobile__c'] ?? '');
        if ($phone !== '') {
            $this->syncContact($people, ContactTypeEnum::PHONE->value, $phone);
        }

        $companyName = trim((string) ($this->payload['Company_Name__c'] ?? ''));
        if ($companyName !== '') {
            new CreateOrganizationAction(
                new OrganizationData(
                    company: $this->company,
                    user: $this->company->user,
                    app: $this->app,
                    name: $companyName,
                ),
            )->execute();
        }

        $this->product->set(CustomFieldEnum::PROPERTY_BROKER_PEOPLE_ID->value, (string) $people->getId());

        return $people;
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', $fullName, 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    private function syncContact(People $people, int $typeId, string $value): void
    {
        $exists = $people->contacts()
            ->where('contacts_types_id', $typeId)
            ->where('value', $value)
            ->exists();

        if (! $exists) {
            $people->contacts()->create([
                'contacts_types_id' => $typeId,
                'value' => $value,
                'weight' => 0,
            ]);
        }
    }
}
