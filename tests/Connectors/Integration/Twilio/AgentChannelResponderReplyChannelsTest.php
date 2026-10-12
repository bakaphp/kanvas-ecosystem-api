<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Override;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * The person and their lead each own a channel for the same phone number. Whichever one the turn
 * ran on, the reply has to show up on both.
 */
class AgentChannelResponderReplyChannelsTest extends TestCase
{
    use DatabaseTransactions;

    public function testReplyOnAPeopleChannelIsAlsoFiledOnTheLastLeadChannel(): void
    {
        $people = $this->makePeople();
        $this->makeLead($people);
        $lastLead = $this->makeLead($people);
        $source = $this->makeChannel($people, $this->slug());

        $reply = $this->respond($source, $this->makeInboundMessage($people));

        $leadChannel = $this->channelOf($lastLead, $source->slug);
        $this->assertNotNull($leadChannel, 'the last lead should get a channel for the same number');
        $this->assertTrue($leadChannel->messages()->where('messages.id', $reply->getId())->exists());
        $this->assertTrue($source->messages()->where('messages.id', $reply->getId())->exists());
        $this->assertSame(1, Channel::where('slug', $source->slug)->where('entity_namespace', People::class)->count());
    }

    public function testReplyOnALeadChannelIsAlsoFiledOnThePeopleChannel(): void
    {
        $people = $this->makePeople();
        $lead = $this->makeLead($people);
        $source = $this->makeChannel($lead, $this->slug());

        $reply = $this->respond($source, $this->makeInboundMessage($lead));

        $peopleChannel = $this->channelOf($people, $source->slug);
        $this->assertNotNull($peopleChannel, 'the person should get a channel for the same number');
        $this->assertTrue($peopleChannel->messages()->where('messages.id', $reply->getId())->exists());
        $this->assertTrue($source->messages()->where('messages.id', $reply->getId())->exists());
    }

    private function respond(Channel $channel, Message $message): Message
    {
        Http::fake();
        // An owned channel queues a knowledge re-index on every message, which needs an embeddings provider.
        Event::fake([KnowledgeIndexRequested::class]);

        $action = new class ($channel, $message, $this->makeAgent()) extends AgentChannelResponderAction {
            #[Override]
            protected function dispatchMessage(string $to, string $from, string $body): void
            {
            }
        };

        $action->execute(['from' => '+19876543210']);

        return Message::query()
            ->where('companies_id', $message->companies_id)
            ->where('id', '>', $message->getId())
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function channelOf(Lead|People $owner, string $slug): ?Channel
    {
        return Channel::query()
            ->where('slug', $slug)
            ->where('entity_namespace', $owner::class)
            ->where('entity_id', $owner->getId())
            ->first();
    }

    private function slug(): string
    {
        return 'twilio-1' . fake()->unique()->numerify('##########');
    }

    private function makeChannel(Lead|People $owner, string $slug): Channel
    {
        return Channel::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $owner->companies_id,
            'users_id' => auth()->user()->getId(),
            'slug' => $slug,
            'name' => 'Sms ' . $owner->getId(),
            'description' => 'Reply fan-out test channel',
            'entity_namespace' => $owner::class,
            'entity_id' => $owner->getId(),
        ]);
    }

    private function makeInboundMessage(Lead|People $entity): Message
    {
        $app = app(Apps::class);
        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($entity->companies_id)
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'test sms message', 'from_me' => false],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);
        $message->addEntity($entity);

        return $message->fresh();
    }

    private function makePeople(): People
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());

        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    private function makeLead(People $people): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $people->companies_id)
            ->create(['people_id' => $people->getId()]);
    }

    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
    }
}
