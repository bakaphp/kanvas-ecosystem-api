<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\PullLeadAction;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Actions\SyncPeopleAction;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Tests\Connectors\Traits\HasELeadConfiguration;
use Tests\TestCase;

final class LeadTest extends TestCase
{
    use HasELeadConfiguration;

    public function testCreateLead()
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

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        //'source' => is_object($lead->source) && $lead->source->name && ! empty($lead->source->description) ? $lead->source->name : 'Lead Link',
        $leadSource = new CreateLeadSourceAction(
            LeadSource::from([
                'name' => 'Lead Link',
                'description' => 'Campaign',
                'leads_types_id' => null,
                'is_active' => true,
                'app' => $app,
                'company' => $company,
            ])
        )->execute();
        $lead->leads_sources_id = $leadSource->getId();
        $lead->saveOrFail();

        $eLead = new SyncLeadAction($lead)->execute();

        $this->assertNotNull($eLead);
        $this->assertNotNull($eLead->id);
        $this->assertNotNull($eLead->customerId);
        $this->assertEquals($lead->get(CustomFieldEnum::OPPORTUNITY_ID->value), $eLead->id);
        $this->assertEquals($lead->people->get(CustomFieldEnum::CUSTOMER_ID->value), $eLead->customerId);
        $this->assertEquals($lead->source->name, $eLead->source);
        $this->assertEquals($lead->source->description, $eLead->upType);
    }

    public function testPullLead()
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

        $leadData = [
            'customerId' => $eleadCustomer->id,
            //'dateIn' => Lead::currentDateIn(),
            'source' => 'Lead Link',
            'status' => 'Active',
            'subStatus' => 'New',
            'upType' => 'Campaign',
        ];

        $newLead = EntitiesLead::create($app, $company, $leadData);

        $pullLead = new PullLeadAction($app, $company, $user)->execute([
            'phones' => [
                $people->getPhones()->first()->value,
            ],
            'emails' => [
                $people->getEmails()->first()->value,
            ],
            'firstname' => $people->firstname,
            'lastname' => $people->lastname,
            'personId' => $eleadCustomer->id,
        ]);

        $this->assertIsArray($pullLead);
        $this->assertArrayHasKey('people_id', $pullLead[0]);
        //$this->assertInstanceOf(Lead::class, $pullLead[0]);
        //$this->assertEquals($newLead->id, $pullLead[0]->get(CustomFieldEnum::OPPORTUNITY_ID->value));
    }
}
