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
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * One Location__c can carry several Location_Contact__c rows (broker, attorney, landlord, the
 * prospect itself) — the caller runs this once per row, not once per location. Each contact becomes
 * its own People, tagged "{role} - {location name} #{variant_id}" so an agent can find "who is the
 * X for Y" by a partial match on either half (see ListPeopleTool); there's no dedicated People<->
 * Variant relation in Kanvas, and that tag is the whole relationship. A second tag captures
 * Interest_Level__c the same way, when Salesforce carries one, standing in for a lead-variant-interest
 * record without a new table.
 */
class PullPropertyContactAction
{
    use SyncsImportedContact;

    private const string INTEREST_TAG_PREFIX = 'Interest';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Variants $variant,
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

        $email = trim((string) ($this->payload['Contact_Email__c'] ?? ''));

        // A broker manages many properties, and Salesforce creates a separate Location_Contact__c
        // row per property-broker relationship — every property that broker manages gets its own
        // Salesforce id for the "same" contact. Deduping by that id alone (the old behavior)
        // therefore created one People per property instead of one per real broker. Email is the
        // one field that's actually stable across those rows, so match on it first; the per-
        // relationship id stays as a fallback for the (rare) broker with no email on file, so a
        // re-sync of that same property still finds the same People instead of duplicating again.
        $people = $email !== '' ? PeoplesRepository::getByEmail($email, $this->company, $this->app) : null;

        $people ??= People::getByCustomFieldTransactionSafe(
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
            // runWorkflow: false — an Organization pulled FROM Salesforce must not trigger a
            // CREATED workflow that could be wired to PushOrganizationActivity, which would push
            // this same, minimally-populated Organization straight back as a duplicate Account.
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

        $suffix = $this->relationshipTagSuffix($this->variant);

        $role = trim((string) ($this->payload['Location_Contact_Type__c'] ?? ''));
        $tags = [];
        if ($role !== '') {
            $peopleType = new ResolvePeopleTypeByNameAction($this->app, $this->company, $this->company->user)
                ->execute($role);
            $people->people_types_id = $peopleType->getId();
            $people->saveOrFail();

            $tags[] = "{$role} - {$this->variant->name}{$suffix}";
        }

        $interestLevel = trim((string) ($this->payload['Interest_Level__c'] ?? ''));
        if ($interestLevel !== '') {
            $tags[] = self::INTEREST_TAG_PREFIX . ": {$interestLevel} - {$this->variant->name}{$suffix}";
        }

        $this->replaceRelationshipTags($people, $this->variant, $tags);

        // Only the primary contact stands in as "the" broker for the variant — the rest of the
        // relationships now live as tags, so this scalar field is never ambiguous across re-syncs.
        if ((bool) ($this->payload['Primary_Location_Contact__c'] ?? false)) {
            $this->variant->set(CustomFieldEnum::PROPERTY_BROKER_PEOPLE_ID->value, (string) $people->getId());
        }

        return $people;
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', $fullName, 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }
}
