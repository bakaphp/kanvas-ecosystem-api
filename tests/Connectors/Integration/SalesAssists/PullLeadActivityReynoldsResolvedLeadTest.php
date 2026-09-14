<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum as ReynoldsConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use ReflectionClass;
use Tests\TestCase;

class PullLeadActivityReynoldsResolvedLeadTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence'];

    /**
     * Regression: on the Reynolds branch the activity resolved the lead as the
     * incoming $entity instead of the lead findReynoldsLead() matched. On a sync
     * pull $entity is a stub with id 0, so CreateSocialChannelsAfterPullAction
     * hit its `id === 0` guard and returned early — the dealer never got the
     * SMS/email channels.
     */
    public function testReynoldsPullCreatesSocialChannelsForTheMatchedLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, '12345');

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['name' => 'Reynolds Pull Test ' . Str::random(6)]);

        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sally',
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
            ]);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $phone = Str::sanitizePhoneNumber($lead->people->getCellPhones()->first()->value);

        $stub = new Lead();
        $stub->companies_id = $company->getId();

        new ReflectionClass(PullLeadActivity::class)->newInstanceWithoutConstructor()
            ->execute($stub, $app, ['phone' => $phone, 'user' => $user]);

        $channelNames = $lead->socialChannels()->pluck('name')->all();

        $this->assertContains(
            'Sms ' . $lead->getId(),
            $channelNames,
            'Reynolds pull must open channels on the matched lead, not on the id=0 stub entity.'
        );
        $this->assertContains('Email ' . $lead->getId(), $channelNames);
    }
}
