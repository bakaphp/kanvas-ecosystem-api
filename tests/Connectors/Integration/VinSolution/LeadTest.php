<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction;
use Kanvas\Connectors\VinSolution\Actions\PushLeadAction;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Leads\Lead as LeadsLead;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasVinsolutionConfiguration;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use HasVinsolutionConfiguration;

    public function testPushLead()
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

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $pushPeopleToVin = new PushLeadAction($lead);
        $vinLead = $pushPeopleToVin->execute();

        $this->assertNotNull($vinLead);
        $this->assertInstanceOf(LeadsLead::class, $vinLead);
        $this->assertEquals($vinLead->id, $lead->get(CustomFieldEnum::LEADS->value));
        $this->assertEquals($vinLead->contactId, $lead->people->get(CustomFieldEnum::CONTACT->value));
        $this->assertNotNull($lead->get(CustomFieldEnum::LEADS->value));
    }

    public function testUpdateLead()
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

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $pushPeopleToVin = new PushLeadAction($lead);
        $vinLead = $pushPeopleToVin->execute();

        $this->assertNotNull($vinLead);
        $this->assertInstanceOf(LeadsLead::class, $vinLead);
        $this->assertEquals($vinLead->id, $lead->get(CustomFieldEnum::LEADS->value));
        $this->assertEquals($vinLead->contactId, $lead->people->get(CustomFieldEnum::CONTACT->value));
        $this->assertNotNull($lead->get(CustomFieldEnum::LEADS->value));

        $lead->title = 'New Title';

        $lead->save();

        $pushPeopleToVin = new PushLeadAction($lead);
        $vinLead = $pushPeopleToVin->execute();
        $this->assertNotNull($vinLead);
        $this->assertInstanceOf(LeadsLead::class, $vinLead);
        $this->assertEquals($vinLead->id, $lead->get(CustomFieldEnum::LEADS->value));
        $this->assertEquals($vinLead->contactId, $lead->people->get(CustomFieldEnum::CONTACT->value));
        $this->assertNotNull($lead->get(CustomFieldEnum::LEADS->value));
    }

    public function testPullLead()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $vinClient = $this->getClient($app, $company, $user);

        //$dealer = Dealer::getById((int) getenv('VINSOLUTIONS_DEALER_ID'));
        //$user = Dealer::getUser($dealer, (int) getenv('VINSOLUTIONS_USER_ID'));

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
                        'EmailAddress' => fake()->email,
                        'EmailType' => 'primary',
                    ],
                ],
                'Phones' => [
                    [
                        'PhoneId' => 0,
                        'PhoneType' => 'Cell',
                        'Number' => '8093505188000',
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

        $lead = [
            'leadSource' => 55694,
            'leadType' => 'INTERNET',
            'contact' => $contact->id,
            'isHot' => true,
        ];

        $newLead = LeadsLead::create($vinCompany, $vinUser, $lead);
        $newLead = LeadsLead::getById($vinCompany, $vinUser, $newLead->id);

        $pullLead = new PullLeadAction(
            app: $app,
            company: $company,
            user: $user,
        )->execute(
            lead: null,
            leadId: $newLead->id
        );

        $this->assertNotNull($pullLead);
        $this->assertInstanceOf(LeadsLead::class, $newLead);
        $this->assertInstanceOf(Lead::class, $pullLead[0]);
        $this->assertEquals($newLead->id, $pullLead[0]->get(CustomFieldEnum::LEADS->value));
        $this->assertEquals($newLead->contactId, $pullLead[0]->people->get(CustomFieldEnum::CONTACT->value));
        $this->assertNotNull($pullLead[0]->get(CustomFieldEnum::LEADS->value));
    }
}
