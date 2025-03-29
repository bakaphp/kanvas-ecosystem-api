<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
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

        $people = People::factory()->withAppId($app->getId())->withUserId($user->getId())->withCompanyId($company->getId())->withContacts()->create();
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
}
