<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Salesforce\Actions\Concerns\SyncsImportedContact;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Inventory\Products\Models\Products;

/**
 * Project_Contact__c's role field (Relationship__c) is a much larger catalog than Location__c's
 * (attorneys, lenders, the deal's own iBanker, "Interested Party"...), and this is the
 * project-level mirror of PullPropertyContactAction — one Project__c can carry several
 * Project_Contact__c rows, the caller runs this once per row. Each becomes its own People, tagged
 * "{role} - {project name} #{product_id}" for the same reason: no dedicated People<->Product
 * relation exists, so the tag is the relationship (see ListPeopleTool). There is no
 * Interest_Level__c-equivalent at the project level — interest is tracked per Location__c only.
 */
class PullProjectContactAction
{
    use SyncsImportedContact;

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
        $firstname = trim((string) ($this->payload['First_Name__c'] ?? ''));
        $lastname = trim((string) ($this->payload['Last_Name__c'] ?? ''));
        if ($firstname === '' && $lastname === '') {
            return null;
        }

        $email = trim((string) ($this->payload['Email__c'] ?? ''));

        // Same reasoning as PullPropertyContactAction: a contact tied to many projects gets a
        // separate Project_Contact__c row per project, so email is matched first and the
        // per-relationship id is only the fallback for a contact with none on file.
        $people = $email !== '' ? PeoplesRepository::getByEmail($email, $this->company, $this->app) : null;

        $people ??= People::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_PROJECT_CONTACT_ID->value,
            $this->salesforceId,
            $this->company,
        );

        if ($people === null) {
            $people = new People();
            $people->apps_id = $this->app->getId();
            $people->companies_id = $this->company->getId();
            $people->users_id = $this->company->user->getId();
        }

        $people->firstname = $firstname !== '' ? $firstname : $lastname;
        $people->lastname = $lastname !== '' ? $lastname : $firstname;
        $people->name = trim("{$firstname} {$lastname}");
        $people->disableWorkflows();
        $people->saveOrFail();
        $people->set(CustomFieldEnum::SALESFORCE_PROJECT_CONTACT_ID->value, $this->salesforceId);

        if ($email !== '') {
            $this->syncContact($people, ContactTypeEnum::EMAIL->value, $email);
        }

        $phone = (string) ($this->payload['Phone__c'] ?? $this->payload['Mobile__c'] ?? '');
        if ($phone !== '') {
            $this->syncContact($people, ContactTypeEnum::PHONE->value, $phone);
        }

        $companyName = trim((string) ($this->payload['Company_Name__c'] ?? ''));
        if ($companyName !== '') {
            // runWorkflow: false — same pull-triggers-push loop guard as PullPropertyContactAction.
            new CreateOrganizationAction(
                new OrganizationData(
                    company: $this->company,
                    user: $this->company->user,
                    app: $this->app,
                    name: $companyName,
                    runWorkflow: false,
                ),
            )->execute();
        }

        $role = trim((string) ($this->payload['Relationship__c'] ?? ''));
        if ($role !== '') {
            $peopleType = new ResolvePeopleTypeByNameAction($this->app, $this->company, $this->company->user)
                ->execute($role);
            $people->people_types_id = $peopleType->getId();
            $people->saveOrFail();

            $suffix = $this->relationshipTagSuffix($this->product);
            $this->replaceRelationshipTags($people, $this->product, ["{$role} - {$this->product->name}{$suffix}"]);
        }

        return $people;
    }
}
