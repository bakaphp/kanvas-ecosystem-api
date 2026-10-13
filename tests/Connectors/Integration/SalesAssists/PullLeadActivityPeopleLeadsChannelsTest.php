<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use ReflectionClass;
use Tests\TestCase;

/**
 * The DealerSocket arm resolves a People when the person has no active lead. That arm calls the
 * DealerSocket API, so the per-lead channel creation it falls back to is driven directly here.
 */
class PullLeadActivityPeopleLeadsChannelsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence', 'ecosystem'];

    public function testOpensChannelsOnEveryLeadOfThePerson(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['name' => 'People Pull Test ' . Str::random(6)]);

        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sally',
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
            ]);

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $leads = Lead::factory()
            ->count(2)
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $reflection = new ReflectionClass(PullLeadActivity::class);
        $activity = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('app')->setValue($activity, $app);
        $reflection->getProperty('company')->setValue($activity, $company);

        $reflection->getMethod('createSocialChannels')->invoke($activity, $people->leads, []);

        foreach ($leads as $lead) {
            $channelNames = $lead->socialChannels()->pluck('name')->all();

            $this->assertContains('Sms ' . $lead->getId(), $channelNames);
            $this->assertContains('Email ' . $lead->getId(), $channelNames);
        }
    }
}
