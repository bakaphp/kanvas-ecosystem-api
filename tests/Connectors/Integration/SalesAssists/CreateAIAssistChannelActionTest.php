<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\CreateAIAssistChannelAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class CreateAIAssistChannelActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Message is searchable via the dynamic per-tenant engine. The test app points at
        // Typesense which is unavailable here; drop the tenant engine so indexing falls back
        // to the null scout driver. ('null' can't be persisted via set() — get() decodes it.)
        $app = app(Apps::class);
        $app->del('search_engine');
        $app->del('messages_search_engine');
        config(['scout.driver' => 'null']);
    }

    public function testPostsGreetingMessageWhenConfigured(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $agent = $this->createAgent($app, $company);

        $greeting = 'Welcome! This channel lets you chat with our AI assistant about this lead. ' . uniqid();
        $company->set(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value, $greeting);

        $result = $this->runAction($lead, $app, $agent);

        $this->assertTrue($result['is_new_channel']);

        $message = $this->channelGreeting($result['channel']->getId());
        $this->assertNotNull($message);
        $this->assertStringContainsString($greeting, (string) $message->message['content']);
    }

    public function testDoesNotPostGreetingWhenNotConfigured(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $agent = $this->createAgent($app, $company);

        $company->del(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value);
        $app->del(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value);

        $result = $this->runAction($lead, $app, $agent);

        $this->assertNull($this->channelGreeting($result['channel']->getId()));
    }

    public function testDoesNotDuplicateGreetingOnExistingChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $agent = $this->createAgent($app, $company);

        $company->set(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value, 'Hello there ' . uniqid());

        $first = $this->runAction($lead, $app, $agent);
        $second = $this->runAction($lead, $app, $agent);

        $this->assertTrue($first['is_new_channel']);
        $this->assertFalse($second['is_new_channel']);

        $count = Message::query()
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $second['channel']->getId()))
            ->count();
        $this->assertSame(1, $count);
    }

    private function runAction(Lead $lead, Apps $app, Agent $agent): array
    {
        return new CreateAIAssistChannelAction($lead, $app, (int) $agent->getId())->execute();
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

    private function channelGreeting(int $channelId): ?Message
    {
        return Message::query()
            ->whereJsonContains('message->from_ia', true)
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $channelId))
            ->latest('id')
            ->first();
    }
}
