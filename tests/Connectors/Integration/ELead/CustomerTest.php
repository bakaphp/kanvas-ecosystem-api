<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\PullPeopleAction;
use Kanvas\Connectors\Elead\Actions\SyncPeopleAction;
use Kanvas\Connectors\Elead\Entities\Customer;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\Connectors\Traits\HasELeadConfiguration;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    use HasELeadConfiguration;

    public function testCreateCustomer()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $eleadClient = $this->getClient($app, $company);

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $eleadCustomer = new SyncPeopleAction($people)->execute();

        $this->assertInstanceOf(Customer::class, $eleadCustomer);
        $this->assertNotEmpty($eleadCustomer->id);
        $this->assertEquals($people->firstname, $eleadCustomer->firstName);
        $this->assertEquals($people->lastname, $eleadCustomer->lastName);
        $this->assertEquals($people->get(CustomFieldEnum::CUSTOMER_ID->value), $eleadCustomer->id);
    }

    public function testUpdateCustomer()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $eleadClient = $this->getClient($app, $company);

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $eleadCustomer = new SyncPeopleAction($people)->execute();

        $this->assertInstanceOf(Customer::class, $eleadCustomer);
        $this->assertNotEmpty($eleadCustomer->id);
        $this->assertEquals($people->firstname, $eleadCustomer->firstName);
        $this->assertEquals($people->lastname, $eleadCustomer->lastName);
        $this->assertEquals($people->get(CustomFieldEnum::CUSTOMER_ID->value), $eleadCustomer->id);

        // Update the customer
        $people->firstname = 'Updated FirstName';
        $people->lastname = 'LastName';
        $people->save();

        // Re-sync the customer
        new SyncPeopleAction($people)->execute();

        // Assert that the customer was updated in ELead
        $updatedEleadCustomer = Customer::getById($app, $company, $eleadCustomer->id);

        $this->assertEquals('Updated FirstName', $updatedEleadCustomer->firstName);
        $this->assertEquals('LastName', $updatedEleadCustomer->lastName);
    }

    public function testPullCustomer()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $eleadClient = $this->getClient($app, $company);

        $customerData = [
            'isBusiness' => false,
            'firstName' => fake()->firstName,
            'middleName' => '',
            'lastName' => fake()->lastName,
            'emails' => [
                [
                    'address' => 'castr@kanvas.dev',
                    'emailType' => 'Personal',
                ],
                [
                    'address' => 'frias@kanvas.dev',
                    'emailType' => 'Work',
                ],
            ],
            'phones' => [
                [
                    'number' => '809-351-3133',
                    'phoneType' => 'Cellular',
                    'preferredTimeToContact' => 'Evening',
                ],
                [
                    'number' => '839-393-3323',
                    'phoneType' => 'Work',
                    'preferredTimeToContact' => 'Day',
                ],
            ],
            'address' => [
                'addressLine1' => '799 E DRAGRAM',
                'city' => 'TUCSON',
                'state' => 'AZ',
                'zip' => '85705',
                'country' => 'USA',
                'county' => 'PIMA',
            ],
        ];

        $newCustomer = Customer::create($app, $company, $customerData);
        $this->assertInstanceOf(Customer::class, $newCustomer);
        $this->assertNotEmpty($newCustomer->id);

        $request = [
            'phones' => [
                $customerData['phones'][0]['number'],
            ],
            'emails' => [
                $customerData['emails'][0]['address'],
            ],
            'firstname' => $customerData['firstName'],
            'lastname' => $customerData['lastName'],
            'personId' => $newCustomer->id,
        ];

        $pullPeople = new PullPeopleAction($app, $company, $user)->execute($request);
        $this->assertNotEmpty($pullPeople);
        $this->assertEquals($customerData['firstName'], $pullPeople[0]->firstname);
        $this->assertEquals($customerData['lastName'], $pullPeople[0]->lastname);
    }
}
