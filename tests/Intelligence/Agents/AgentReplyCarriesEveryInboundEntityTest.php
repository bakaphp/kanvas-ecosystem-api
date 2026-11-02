<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\TestCase;

/**
 * Connectors attach a People row and a Lead row to every inbound, and app_module_message has no
 * ordering, so the reply must carry both whichever row comes back first.
 */
class AgentReplyCarriesEveryInboundEntityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testReplyLinksTheLeadAndThePeopleWhenThePeopleRowComesFirst(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $people = People::getById((int) $lead->people_id);

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'whatsapp'],
            ['name' => 'WhatsApp']
        );

        $channel = Channel::firstOrCreate(
            ['apps_id' => $app->getId(), 'companies_id' => $company->getId(), 'slug' => 'entity-carry-' . $lead->getId()],
            ['name' => 'Test', 'description' => 't', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'Hello', 'from_me' => false, 'chat_jid' => 'test'],
            ]);
        $inbound->addEntity($people);
        $inbound->addEntity($lead);
        $channel->addMessage($inbound);

        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => 'TestAgent',
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
        ]);

        $action = new class ($channel, $inbound, $agent) extends BaseAgentChannelReplyAction {
            public function reply(string $text, Message $inbound, Channel $channel): Message
            {
                return $this->createMessage($text, 'test', $inbound, $channel);
            }
        };

        $reply = $action->reply('Hi there', $inbound->fresh(), $channel);
        $entities = $reply->fresh()->entities();

        $this->assertCount(2, $entities);
        $this->assertSame($lead->getId(), $entities->first(fn (Model $entity) => $entity instanceof Lead)?->getId());
        $this->assertSame($people->getId(), $entities->first(fn (Model $entity) => $entity instanceof People)?->getId());
    }
}
