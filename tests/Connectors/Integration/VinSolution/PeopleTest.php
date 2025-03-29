<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushPeopleAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Guild\Customers\Models\People;
use Tests\Connectors\Traits\HasVinsolutionConfiguration;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use HasVinsolutionConfiguration;

    public function testPushPeople()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $vinClient = $this->getClient($app, $company, $user);

        $people = People::factory()->withAppId($app->getId())->withUserId($user->getId())->withCompanyId($company->getId())->withContacts()->create();

        $pushPeopleToVin = new PushPeopleAction($people);
        $customer = $pushPeopleToVin->execute();

        $this->assertNotNull($customer);
        $this->assertInstanceOf(Contact::class, $customer);
        $this->assertEquals($people->firstname, $customer->information['FirstName']);
        $this->assertNotNull($people->get(CustomFieldEnum::CONTACT->value));
    }

    public function testUpdatePeople()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $vinClient = $this->getClient($app, $company, $user);

        $people = People::factory()->withAppId($app->getId())->withUserId($user->getId())->withCompanyId($company->getId())->withContacts()->create();

        $pushPeopleToVin = new PushPeopleAction($people);
        $customer = $pushPeopleToVin->execute();

        $this->assertNotNull($customer);
        $this->assertInstanceOf(Contact::class, $customer);
        $this->assertEquals($people->firstname, $customer->information['FirstName']);
        $this->assertNotNull($people->get(CustomFieldEnum::CONTACT->value));

        // Update the people
        $people->firstname = 'Updated';
        $people->save();

        // Push the updated people to VinSolution
        $pushPeopleToVin = new PushPeopleAction($people);
        $updatedCustomer = $pushPeopleToVin->execute();

        // Assert that the updated customer information is correct
        $this->assertEquals('Updated', $updatedCustomer->information['FirstName']);
    }
}
