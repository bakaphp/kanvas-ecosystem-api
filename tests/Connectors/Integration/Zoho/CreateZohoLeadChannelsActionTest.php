<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Actions\CreateZohoLeadChannelsAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

final class CreateZohoLeadChannelsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence', 'ecosystem'];

    public function testACellphoneGetsAnSmsChannel(): void
    {
        $this->createAgent();
        $lead = $this->createLead();
        $lead->people->addCellPhone('939555' . random_int(1000, 9999));

        new CreateZohoLeadChannelsAction($lead)->execute();

        $this->assertContains('Sms ' . $lead->getId(), $lead->socialChannels()->pluck('name')->all());
    }

    public function testAHomePhoneGetsNoSmsChannel(): void
    {
        $this->createAgent();
        $lead = $this->createLead();
        $lead->people->addPhone('939555' . random_int(1000, 9999));

        new CreateZohoLeadChannelsAction($lead)->execute();

        $this->assertNotContains('Sms ' . $lead->getId(), $lead->socialChannels()->pluck('name')->all());
    }

    private function createAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['name' => 'Zoho Channels Test ' . Str::random(6)]);

        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'name' => 'Zoho Channels Test',
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
            ]);
    }

    private function createLead(): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
