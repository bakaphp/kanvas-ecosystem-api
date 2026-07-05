<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SystemUserAgentStub;
use Tests\TestCase;

class RespondToMentionJobTest extends TestCase
{
    private function makeAgentUser(string $displayname): Users
    {
        // A fully-registered user (with an app profile) — how PR8 provisioning makes a bot-user.
        $user = $this->createUser();
        $user->displayname = $displayname;
        $user->firstname = 'Inventory';
        $user->lastname = 'Bot';
        $user->saveQuietly();

        return $user;
    }

    private function makeAgent(Users $agentUser): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => SystemUserAgentStub::class]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'user_id' => $agentUser->getId()]);
    }

    private function makeChannel(Users $owner): Channel
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $owner,
                entity_id: $owner->getId(),
                entity_namespace: Users::class,
                name: 'Mention Test',
                slug: 'mention-test-' . uniqid(),
            ),
        )->execute();
    }

    private function makeMessage(Users $author, string $content, ?int $parentId = null): Message
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $author,
                type: MessageTypeService::getOrCreate($app, 'note'),
                message: ['content' => $content, 'from_ia' => false],
                parent_id: $parentId,
                is_public: 1,
            ),
        );
        $action->runWorkflow = false;

        return $action->execute();
    }

    public function testAgentUserMentionGetsAChildReply(): void
    {
        $human = auth()->user();

        $agentUser = $this->makeAgentUser('InventoryBot');
        $agent = $this->makeAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, 'hey @InventoryBot can you help with this');
        $channel->addMessage($mention, $human);

        new RespondToMentionJob($agent, $mention)->handle();

        $reply = Message::where('parent_id', $mention->getId())->latest('id')->first();

        $this->assertNotNull($reply, 'The agent must reply as a child of the mentioning message');
        $this->assertStringContainsString('Hola Sistema', (string) $reply->getMessage()['content']);
        $this->assertTrue((bool) ($reply->getMessage()['from_ia'] ?? false));
    }

    public function testReplyStaysOneLevelDeepWhenMentionedInsideAChild(): void
    {
        $human = auth()->user();

        $agentUser = $this->makeAgentUser('InventoryBot');
        $agent = $this->makeAgent($agentUser);

        $channel = $this->makeChannel($human);
        $root = $this->makeMessage($human, 'root topic');
        $channel->addMessage($root, $human);

        // A human reply INSIDE the thread that @mentions the agent again.
        $childMention = $this->makeMessage($human, '@InventoryBot follow up here', $root->getId());
        $channel->addMessage($childMention, $human);

        new RespondToMentionJob($agent, $childMention)->handle();

        $reply = Message::where('parent_id', $root->getId())
            ->where('id', '!=', $childMention->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($reply, 'The reply must anchor to the thread root, not the child');
        $this->assertSame($root->getId(), (int) $reply->parent_id);
        $this->assertNull(
            Message::where('parent_id', $childMention->getId())->first(),
            'No grandchild reply — the thread must stay one level deep',
        );
    }

    public function testDoesNotReplyToAMessageItsOwnUserAuthored(): void
    {
        $agentUser = $this->makeAgentUser('InventoryBot');
        $agent = $this->makeAgent($agentUser);

        $channel = $this->makeChannel($agentUser);
        $selfMessage = $this->makeMessage($agentUser, '@InventoryBot note to self');
        $channel->addMessage($selfMessage, $agentUser);

        new RespondToMentionJob($agent, $selfMessage)->handle();

        $this->assertNull(
            Message::where('parent_id', $selfMessage->getId())->first(),
            'An agent must not reply to a message its own user authored',
        );
    }

    public function testListenerDispatchesJobForAnAgentUserMention(): void
    {
        Bus::fake([RespondToMentionJob::class]);

        $human = auth()->user();
        $agentUser = $this->makeAgentUser('InventoryBot');
        $this->makeAgent($agentUser);
        $message = $this->makeMessage($human, 'plain note');

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [$agentUser->getId()]),
        );

        Bus::assertDispatched(RespondToMentionJob::class);
    }

    public function testListenerIgnoresMentionsOfPlainHumans(): void
    {
        Bus::fake([RespondToMentionJob::class]);

        $human = auth()->user();
        $message = $this->makeMessage($human, 'plain note');

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [$human->getId()]),
        );

        Bus::assertNotDispatched(RespondToMentionJob::class);
    }
}
