<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithOrganizationAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use NetSuite\Classes\Address;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerAddressbook;
use NetSuite\Classes\CustomerAddressbookList;
use NetSuite\Classes\RecordType;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\TestCase;

class SyncNetSuiteCustomerWithOrganizationActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social'];

    private FakeNetSuiteClient $client;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = auth()->user()->getCurrentCompany();
        $this->client = new FakeNetSuiteClient(app(Apps::class), $this->company);
    }

    private function action(): SyncNetSuiteCustomerWithOrganizationAction
    {
        $action = new SyncNetSuiteCustomerWithOrganizationAction(app(Apps::class), $this->company, auth()->user());
        $this->client->into(new ReflectionProperty($action, 'service')->getValue($action));

        return $action;
    }

    private function customer(string $internalId, ?string $email = 'julie@artsupplydepo.test'): Customer
    {
        $address = new Address();
        $address->attention = 'Julie Lynn';
        $address->addr1 = '5739 Main Street';
        $address->city = 'Sylvania';
        $address->state = 'OH';
        $address->zip = '43560';

        $entry = new CustomerAddressbook();
        $entry->defaultBilling = true;
        $entry->addressbookAddress = $address;

        $customer = new Customer();
        $customer->internalId = $internalId;
        $customer->entityId = 'C' . $internalId;
        $customer->companyName = 'The Art Supply Depo ' . $internalId;
        $customer->email = $email;
        $customer->addressbookList = new CustomerAddressbookList();
        $customer->addressbookList->addressbook = [$entry];

        return $customer;
    }

    public function testTheCustomerBecomesAnOrganizationWithABillingContact(): void
    {
        $id = (string) random_int(100000, 999999);
        $email = 'julie+' . Str::random(8) . '@artsupplydepo.test';
        $this->client->soap->serve(RecordType::customer, $this->customer($id, $email));

        $organization = $this->action()->execute($id);

        $this->assertSame('The Art Supply Depo ' . $id, $organization->name);
        $this->assertSame($this->company->getId(), (int) $organization->companies_id);
        $this->assertSame('5739 Main Street', $organization->address);
        $this->assertSame($id, (string) $organization->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value));

        $contact = $organization->peoples()->first();
        $this->assertNotNull($contact, 'The bill-to attention is linked as a person.');
        $this->assertSame('Julie', $contact->firstname);
        $this->assertSame('Lynn', $contact->lastname);
        $this->assertTrue(new BillingContactResolverService()->isBillingContact($contact));
    }

    public function testASecondRunReusesTheOrganizationWithoutCallingNetSuite(): void
    {
        $id = (string) random_int(100000, 999999);
        $this->client->soap->serve(RecordType::customer, $this->customer($id, 'julie+' . Str::random(8) . '@artsupplydepo.test'));

        $first = $this->action()->execute($id);
        $this->client->soap->gets = [];
        $second = $this->action()->execute($id);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame([], $this->client->soap->gets);
    }

    public function testACustomerWithoutAnEmailGetsAContactButNoBillingTag(): void
    {
        $id = (string) random_int(100000, 999999);
        $this->client->soap->serve(RecordType::customer, $this->customer($id, null));

        $organization = $this->action()->execute($id);

        $contact = $organization->peoples()->first();
        $this->assertNotNull($contact, 'An order needs a person even without an email.');
        $this->assertFalse(new BillingContactResolverService()->isBillingContact($contact));
    }
}
