<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Arr;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use NetSuite\Classes\Address as NetSuiteAddress;
use NetSuite\Classes\Customer;
use Spatie\LaravelData\DataCollection;

/**
 * The NetSuite customer as the Guild Organization a Scribe invoice bills, inside the importing company.
 * Not SyncNetSuiteCustomerWithCompanyAction: that one makes a Kanvas company for portal logins.
 * The billing contact is the bill-to attention ("Julie Lynn"), tagged so the payment link and a later
 * send_invoice find them.
 */
class SyncNetSuiteCustomerWithOrganizationAction
{
    protected NetSuiteCustomerService $service;

    public function __construct(protected Apps $app, protected Companies $company, protected UserInterface $user)
    {
        $this->service = new NetSuiteCustomerService($app, $company);
    }

    public function execute(int|string $customerId): Organization
    {
        /** @var Organization|null $existing */
        $existing = Organization::getByCustomFieldTransactionSafe(
            CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value,
            (string) $customerId,
            $this->company
        );

        if ($existing !== null) {
            return $existing;
        }

        $customer = $this->service->getCustomerById($customerId);
        $address = $this->billingAddress($customer);

        $organization = new CreateOrganizationAction(
            new OrganizationData(
                company: $this->company,
                user: $this->user,
                app: $this->app,
                name: Str::trimToNull($customer->companyName) ?? Str::trimToNull($customer->entityId) ?? (string) $customerId,
                email: Str::trimToNull($customer->email),
                phone: Str::trimToNull($customer->phone),
                address: Str::trimToNull($address?->addr1),
                city: Str::trimToNull($address?->city),
                state: Str::trimToNull($address?->state),
                zip: Str::trimToNull($address?->zip),
                runWorkflow: false,
            ),
        )->execute();

        $organization->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, (string) $customerId);

        $this->syncBillingContact($organization, $customer, $address);

        return $organization;
    }

    /**
     * Always made, because a Souk order needs a person; tagged as billing contact only with an email,
     * since that tag is what a payment link and send_invoice deliver to.
     */
    private function syncBillingContact(Organization $organization, Customer $customer, ?NetSuiteAddress $address): void
    {
        $email = Str::trimToNull($customer->email);

        $name = Str::trimToNull($customer->firstName) !== null
            ? trim($customer->firstName . ' ' . $customer->lastName)
            : (Str::trimToNull($address?->attention) ?? $organization->name);
        [$firstname, $lastname] = array_pad(explode(' ', $name, 2), 2, null);

        $contacts = [];

        if ($email !== null) {
            $contacts[] = ['value' => $email, 'contacts_types_id' => ContactTypeEnum::EMAIL->value];
        }

        if (($phone = Str::trimToNull($customer->phone)) !== null) {
            $contacts[] = ['value' => $phone, 'contacts_types_id' => ContactTypeEnum::PHONE->value];
        }

        $people = new CreatePeopleAction(
            new PeopleData(
                app: $this->app,
                branch: $this->company->defaultBranch,
                user: $this->user,
                firstname: $firstname,
                contacts: Contact::collect($contacts, DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $lastname,
                organization: $organization->name,
                runWorkflow: false,
            )
        )->execute();

        if ($email === null) {
            $organization->addPeople($people);

            return;
        }

        new BillingContactResolverService()->markBillingContact($people, $organization);
    }

    private function billingAddress(Customer $customer): ?NetSuiteAddress
    {
        $entries = Arr::wrap($customer->addressbookList->addressbook ?? null);

        foreach ($entries as $entry) {
            if ($entry->defaultBilling ?? false) {
                return $entry->addressbookAddress;
            }
        }

        return $entries[0]->addressbookAddress ?? null;
    }
}
