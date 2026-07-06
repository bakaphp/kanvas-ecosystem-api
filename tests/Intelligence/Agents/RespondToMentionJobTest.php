<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\History\ChannelMessageHistory;
use Kanvas\Intelligence\Notifications\AgentRepliedToMentionNotification;
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
    protected function setUp(): void
    {
        parent::setUp();

        // The reply triggers AgentRepliedToMentionNotification, which renders per-app templates.
        // Fake it so tests never depend on seeded templates (the assertion test still works).
        Notification::fake();
    }

    private function makeAgentUser(string $displayname): Users
    {
        // A fully-registered user (with an app profile) — how PR8 provisioning makes a bot-user.
        $user = $this->registerFreshUser();
        $user->displayname = $displayname;
        $user->firstname = 'Inventory';
        $user->lastname = 'Bot';
        $user->saveQuietly();

        return $user;
    }

    private function registerFreshUser(): Users
    {
        // Unique email — the shared, un-transacted test DB accumulates users, so fake()->email collides.
        $dto = RegisterPostDataDto::from([
            'email' => 'agent-' . uniqid('', true) . '@example.test',
            'password' => 'Password123!',
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
        ]);

        return new RegisterUsersAction($dto)->execute();
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

    private function makeChannel(Users $owner, ?Model $entity = null): Channel
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $entity ??= $owner;

        return new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $owner,
                entity_id: (int) $entity->getKey(),
                entity_namespace: $entity::class,
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

    public function testMentioningUserIsNotifiedWhenTheAgentReplies(): void
    {
        $human = auth()->user();
        $agentUser = $this->makeAgentUser('InventoryBot');
        $agent = $this->makeAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, 'hey @InventoryBot can you help with this');
        $channel->addMessage($mention, $human);

        new RespondToMentionJob($agent, $mention)->handle();

        Notification::assertSentTo($human, AgentRepliedToMentionNotification::class);
    }

    public function testUsesTheChannelEntityAsContextWhenTheMentionHasNoEntity(): void
    {
        $human = auth()->user();
        $app = app(Apps::class);
        $company = $human->getCurrentCompany();

        $agentUser = $this->makeAgentUser('InventoryBot');
        $agent = $this->makeAgent($agentUser);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $channel = $this->makeChannel($human, $lead);

        // The mention comment is linked to nothing itself — only the channel carries the lead.
        $mention = $this->makeMessage($human, '@InventoryBot summarize this lead');
        $channel->addMessage($mention, $human);
        $this->assertNull($mention->entity(), 'Precondition: the mention has no entity of its own');

        new RespondToMentionJob($agent, $mention)->handle();

        $reply = Message::where('parent_id', $mention->getId())->latest('id')->first();

        $this->assertNotNull($reply);
        $this->assertInstanceOf(
            Lead::class,
            $reply->entity(),
            'The reply must inherit the channel\'s lead so the agent replies with lead context',
        );
        $this->assertSame($lead->getId(), $reply->entity()?->getId());
    }

    public function testChannelHistoryLabelsEachHumanTurnWithItsAuthor(): void
    {
        $alice = $this->registerFreshUser();
        $alice->firstname = 'Alice';
        $alice->displayname = 'alice-' . $alice->getId();
        $alice->saveQuietly();

        $bob = $this->registerFreshUser();
        $bob->firstname = 'Bob';
        $bob->displayname = 'bob-' . $bob->getId();
        $bob->saveQuietly();

        $channel = $this->makeChannel($alice);
        $channel->addMessage($this->makeMessage($alice, 'hello from alice'), $alice);
        $channel->addMessage($this->makeMessage($bob, 'hi this is bob'), $bob);

        $text = '';
        foreach (new ChannelMessageHistory($channel)->getMessages() as $turn) {
            $text .= (is_string($turn->getContent()) ? $turn->getContent() : '') . "\n";
        }

        $this->assertStringContainsString('(@alice-' . $alice->getId() . '): hello from alice', $text);
        $this->assertStringContainsString('(@bob-' . $bob->getId() . '): hi this is bob', $text);
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

        // A user that is NOT an agent — the shared auth user can be bound to an agent by other
        // tests leaking through the un-transacted test DB, which would false-positive here.
        $plainHuman = Users::factory()->create();
        $message = $this->makeMessage(auth()->user(), 'plain note');

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [$plainHuman->getId()]),
        );

        Bus::assertNotDispatched(RespondToMentionJob::class);
    }
}
