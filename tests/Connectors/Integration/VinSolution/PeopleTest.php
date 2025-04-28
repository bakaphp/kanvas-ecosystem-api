<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PullPeopleAction;
use Kanvas\Connectors\VinSolution\Actions\PushPeopleAction;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
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

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

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

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

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

    public function testPullPeople()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $vinClient = $this->getClient($app, $company, $user);

        $email = 'noreply+' . fake()->unique()->userName . '@kanvas.dev';
        $phone = '80935' . fake()->randomNumber(5, true);

        $contact = [
            'ContactInformation' => [
                'title' => fake()->title(),
                'FirstName' => fake()->firstName(),
                'LastName' => fake()->lastName,
                'CompanyName' => fake()->company,
                'CompanyType' => fake()->companySuffix,
                'Emails' => [
                    [
                        'EmailId' => 0,
                        'EmailAddress' => $email,
                        'EmailType' => 'primary',
                    ],
                ],
                'Phones' => [
                    [
                        'PhoneId' => 0,
                        'PhoneType' => 'Cell',
                        'Number' => $phone,
                    ],
                ],
            ],
            'LeadInformation' => [
                'CurrentSalesRepUserId' => 0,
                'SplitSalesRepUserId' => 0,
                'LeadSourceId' => 0,
                'LeadTypeId' => 0,
                'OnShowRoom' => false,
                'SaleNotes' => '',
            ],
        ];

        $vinCompany = Dealer::getById($vinClient->dealerId, $app);
        $vinUser = Dealer::getUser($vinCompany, $vinClient->userId, $app);

        $contact = Contact::create(
            $vinCompany,
            $vinUser,
            $contact
        );

        $contact = Contact::getById(
            $vinCompany,
            $vinUser,
            $contact->id
        );

        $pullPeople = new PullPeopleAction(
            $app,
            $company,
            $user
        )->execute(
            email: $email,
        );

        $this->assertNotNull($pullPeople);
        $this->assertInstanceOf(People::class, $pullPeople[0]);
        $this->assertEquals($pullPeople[0]->firstname, $contact->information['FirstName']);
        $this->assertNotNull($pullPeople[0]->get(CustomFieldEnum::CONTACT->value));
    }
}
