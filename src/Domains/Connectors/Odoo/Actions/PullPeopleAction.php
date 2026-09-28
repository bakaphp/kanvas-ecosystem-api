<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Connectors\Odoo\Actions\Concerns\ParsesOdooPayload;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\DataCollection;

/**
 * A `res.partner` row with `is_company = false` — see PullOrganizationAction for the `true` case.
 */
class PullPeopleAction
{
    use ParsesOdooPayload;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $payload,
        protected string $odooId,
    ) {
    }

    public function execute(): People
    {
        $branch = $this->company->defaultBranch ?? $this->company->user->getCurrentCompany()->branch;

        ['firstname' => $firstName, 'lastname' => $lastName] = Str::parseFullName(
            $this->payloadString('name') ?? 'Unknown'
        );

        $contacts = $this->contactsFromPayload('email', 'phone');

        $peopleData = new PeopleData(
            app: $this->app,
            branch: $branch,
            user: $this->company->user,
            firstname: $firstName,
            contacts: Contact::collect($contacts, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $lastName,
            custom_fields: [
                CustomFieldEnum::ODOO_CONTACT_ID->value => $this->odooId,
            ],
            runWorkflow: false,
            // Identity comes from the Odoo partner id custom field above — a shared phone/email
            // with an unrelated existing People is a duplicate for the merge/dedup flow to catch,
            // not a reason to silently fold this contact into that other record.
            skipDuplicateContactCheck: true,
        );

        $people = new SyncPeopleByThirdPartyCustomFieldAction($peopleData)->execute();

        $parentOdooId = $this->relationId($this->payload['parent_id'] ?? null);
        if ($parentOdooId !== null) {
            /** @var Organization|null $organization */
            $organization = Organization::getByCustomFieldTransactionSafe(
                CustomFieldEnum::ODOO_ACCOUNT_ID->value,
                $parentOdooId,
                $this->company,
            );

            // Link via the pivot directly instead of the People DTO's `organization` name field —
            // that path funnels through CreateOrganizationAction, which always fires a workflow.
            $organization?->addPeople($people);
        }

        return $people;
    }
}
