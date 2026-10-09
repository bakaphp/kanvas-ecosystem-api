<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\CreateSocialChannelsAfterPullAction;
use Kanvas\Connectors\SalesAssist\Activities\CreateSocialChannelActivity;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;

final class CreateSocialChannelsForPeopleTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testAfterPullActionOpensTheContactChannelsOnThePerson(): void
    {
        $app = app(Apps::class);
        $people = $this->makePeople();
        $agent = $this->createAgent($app, $people->company);
        $people->company->del(ConfigurationEnum::AI_ASSIST_ENABLED->value);
        $app->del(ConfigurationEnum::AI_ASSIST_ENABLED->value);

        new CreateSocialChannelsAfterPullAction($people, $app, [], (int) $agent->getId())->execute();

        $channels = $people->socialChannels()->where('name', '!=', ChannelNameEnum::NOTES->value)->get();

        $this->assertEqualsCanonicalizing(
            ['Sms ' . $people->getId(), 'Email ' . $people->getId()],
            $channels->pluck('name')->all()
        );

        $channels->each(function (Channel $channel) use ($people, $agent): void {
            $this->assertSame(People::class, $channel->entity_namespace);
            $this->assertTrue(
                Session::where('channel_id', $channel->getId())
                    ->where('agents_id', $agent->getId())
                    ->where('entity_namespace', People::class)
                    ->where('entity_id', $people->getId())
                    ->exists()
            );
        });
    }

    public function testAfterPullActionOpensTheAiAssistChannelOnThePerson(): void
    {
        $app = app(Apps::class);
        $people = $this->makePeople();
        $agent = $this->createAgent($app, $people->company);
        $people->company->set(ConfigurationEnum::AI_ASSIST_ENABLED->value, true);
        $people->company->del(ConfigurationEnum::AI_ASSIST_AGENT_ID->value);
        $people->company->del(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value);
        $app->del(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value);

        new CreateSocialChannelsAfterPullAction($people, $app, [], (int) $agent->getId())->execute();

        $aiAssist = $people->socialChannels()->where('name', ChannelNameEnum::AI_ASSIST->value)->first();

        $this->assertNotNull($aiAssist);
        $this->assertSame('ai-assist-people-' . $people->getId(), $aiAssist->slug);

        $people->company->del(ConfigurationEnum::AI_ASSIST_ENABLED->value);
    }

    public function testActivityAcceptsAPerson(): void
    {
        $app = app(Apps::class);
        $people = $this->makePeople();
        $agent = $this->createAgent($app, $people->company);
        $people->company->del(ConfigurationEnum::AI_ASSIST_ENABLED->value);
        $app->del(ConfigurationEnum::AI_ASSIST_ENABLED->value);

        $this->wireInternalIntegration();

        $activity = new CreateSocialChannelActivity(0, now()->toDateTimeString(), new StoredWorkflow(), []);
        $result = $activity->execute($people, $app, ['agent_id' => $agent->getId()]);

        $this->assertTrue($result['success']);
        $this->assertNull($result['crm_note']);
        $this->assertSame(
            2,
            $people->socialChannels()->where('name', '!=', ChannelNameEnum::NOTES->value)->count()
        );
    }

    private function wireInternalIntegration(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $region = Regions::getDefault($company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        IntegrationsCompany::firstOrCreate(
            [
                'companies_id' => $company->getId(),
                'integrations_id' => Integrations::getByName(IntegrationsEnum::INTERNAL->value)->getId(),
                'region_id' => $region->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
                'is_active' => 1,
            ]
        );
    }

    private function makePeople(): People
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $people->contacts()->delete();
        $people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '1809555' . random_int(1000, 9999),
            'weight' => 0,
        ]);
        $people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'people-' . uniqid() . '@example.com',
            'weight' => 0,
        ]);

        return $people->refresh();
    }

    private function createAgent(Apps $app, $company): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);
    }
}
