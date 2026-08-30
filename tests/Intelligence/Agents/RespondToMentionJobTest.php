<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\History\ChannelMessageHistory;
use Kanvas\Intelligence\Notifications\AgentRepliedToMentionNotification;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\ParseMessageMentionsAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\BoardPostingSystemUserAgentStub;
use Tests\Stubs\Intelligence\CapturingNeuronProvider;
use Tests\Stubs\Intelligence\CapturingSystemUserAgentStub;
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

    private function makeMessage(
        Users $author,
        string $content,
        ?int $parentId = null,
        bool $fromIa = false,
        bool $fromAgent = false,
    ): Message {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $author,
                type: MessageTypeService::getOrCreate($app, 'note'),
                // `from_agent` is what a board tool stamps; `from_ia` is what a turn reply stamps.
                message: ['content' => $content, 'from_ia' => $fromIa, 'from_agent' => $fromAgent],
                parent_id: $parentId,
                is_public: 1,
            ),
        );
        $action->runWorkflow = false;

        return $action->execute();
    }

    public function testChannelHistoryDropsLeadingAgentTurnsInsteadOfThrowing(): void
    {
        $human = auth()->user();
        $agentUser = $this->makeAgentUser('InventoryBot');

        // A channel the agent opened: the first turn on record is the agent's, not a human's.
        // Providers reject a history that starts with an assistant turn, and NeuronAI's trimmer
        // validates alternation on every add — so these must be dropped as the history loads.
        $channel = $this->makeChannel($human);
        $channel->addMessage($this->makeMessage($agentUser, 'Heads up: 3 SKUs are low', fromIa: true), $agentUser);
        $channel->addMessage($this->makeMessage($human, 'thanks, which ones?'), $human);

        $turns = new ChannelMessageHistory($channel)->getMessages();

        $this->assertNotEmpty($turns);
        $this->assertSame(MessageRole::USER->value, $turns[0]->getRole());
        $this->assertStringContainsString('thanks, which ones?', (string) $turns[0]->getContent());
        $this->assertStringNotContainsString(
            '3 SKUs are low',
            implode("\n", array_map(fn ($turn): string => (string) $turn->getContent(), $turns)),
        );
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

    /**
     * One turn is one message here too.
     *
     * An agent can answer with a board tool mid-turn and then have its reply posted to the same
     * channel — on plan 26531 that was two messages three seconds apart, the second restating the
     * first. The project and worker wakes were already guarded; this path was the one left.
     */
    public function testTheReplyIsSkippedWhenTheAgentAlreadyPostedDuringTheTurn(): void
    {
        $human = auth()->user();
        $agentUser = $this->makeAgentUser('InventoryBot');
        $agent = $this->makeAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, 'hey @InventoryBot can you help with this');
        $channel->addMessage($mention, $human);

        // The agent answers on the board mid-turn, as one holding a board tool does. Posting it before
        // the run would just be history — the duplicate only exists in this ordering.
        $agent->type->handler = BoardPostingSystemUserAgentStub::class;
        $agent->type->saveQuietly();
        BoardPostingSystemUserAgentStub::$postToChannelId = (int) $channel->getId();

        try {
            new RespondToMentionJob($agent->refresh(), $mention)->handle();
        } finally {
            BoardPostingSystemUserAgentStub::$postToChannelId = null;
        }

        $this->assertNull(
            Message::where('parent_id', $mention->getId())->latest('id')->first(),
            'The comment is the answer — the turn must not restate it.',
        );
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

    public function testMentionGivesTheAgentTheChannelSessionAndTheMentioningHuman(): void
    {
        // Regression: the @mention path ran session-less, so a reminder the agent scheduled from a
        // comment stored no channel/session (nothing to deliver back into) and defaulted its recipient
        // to the agent's OWN user — the reminder fired into the void.
        CapturingSystemUserAgentStub::$lastSession = null;
        CapturingSystemUserAgentStub::$lastConversationHuman = null;

        $app = app(Apps::class);
        $human = auth()->user();
        $company = $human->getCurrentCompany();

        $agentUser = $this->makeAgentUser('ReminderBot');
        $agent = $this->makeCapturingAgent($agentUser);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $channel = $this->makeChannel($human, $lead);
        $mention = $this->makeMessage($human, '@ReminderBot remind me to call her next week');
        $channel->addMessage($mention, $human);

        new RespondToMentionJob($agent, $mention)->handle();

        $session = CapturingSystemUserAgentStub::$lastSession;
        $this->assertNotNull($session, 'The mention turn must carry a session so scheduled work can come back here');
        $this->assertSame($channel->getId(), (int) $session->channel_id);
        $this->assertSame(
            SessionChannelService::buildChannelSessionUuid($channel, $app, $company),
            $session->uuid,
            'The session must reuse the canonical channel key so chat and mention share one conversation',
        );
        $this->assertSame(
            $human->getId(),
            CapturingSystemUserAgentStub::$lastConversationHuman?->getId(),
            '"remind me" must resolve to the person who mentioned the agent, not the agent itself',
        );
    }

    public function testTheReplyIsRecordedInTheLedgerForCrossEntityMemory(): void
    {
        $human = auth()->user();
        $app = app(Apps::class);
        $company = $human->getCurrentCompany();

        $agentUser = $this->makeAgentUser('InventoryBot');
        $agent = $this->makeAgent($agentUser);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $channel = $this->makeChannel($human, $lead);
        $mention = $this->makeMessage($human, '@InventoryBot summarize this lead');
        $channel->addMessage($mention, $human);

        new RespondToMentionJob($agent, $mention)->handle();

        $event = Event::query()
            ->where('event_type', 'agent.mention.replied')
            ->where('actor_type', 'Agent')
            ->where('actor_id', $agent->getId())
            ->where('source_entity_type', Lead::class)
            ->where('source_entity_id', $lead->getId())
            ->first();

        $this->assertNotNull($event, 'The reply must be appended to the ledger so the agent remembers this work later');
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

    public function testAFileAttachedToTheMentionIsForwardedToTheAgentAsAContentBlock(): void
    {
        // Regression: the @mention job used to hardcode media: [] into RunNeuronChatAction, so a PDF a
        // user attached to the comment never reached the model — the agent replied "I can't read the
        // file." The job must now collect the message's files and hand them over as native content
        // blocks (RunNeuronChatAction sniffs the bytes → FileContent for a PDF).
        CapturingNeuronProvider::$lastMessages = [];

        $human = auth()->user();
        $agentUser = $this->makeAgentUser('ContractBot');
        $agent = $this->makeCapturingAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, '@ContractBot the signed contract is attached, please review');
        $channel->addMessage($mention, $human);
        $this->attachPdf($mention, $agent->app);

        new RespondToMentionJob($agent, $mention)->handle();

        $this->assertNotEmpty(
            CapturingNeuronProvider::$lastMessages,
            'The agent must have been invoked with the mention turn',
        );

        $sawPdfBlock = false;
        foreach (CapturingNeuronProvider::$lastMessages as $message) {
            foreach ($message->getContentBlocks() as $block) {
                if ($block instanceof FileContent && $block->mediaType === 'application/pdf') {
                    $sawPdfBlock = true;
                }
            }
        }

        $this->assertTrue(
            $sawPdfBlock,
            'The PDF attached to the @mention must reach the model as a native FileContent block',
        );
    }

    public function testMentionWithNoFilesAttachesNoMediaBlock(): void
    {
        // The collection must be inert when there's nothing attached — a plain @mention still rides
        // its text (NeuronAI wraps it as a TextContent block), but no media block is ever attached.
        CapturingNeuronProvider::$lastMessages = [];

        $human = auth()->user();
        $agentUser = $this->makeAgentUser('ContractBot');
        $agent = $this->makeCapturingAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, '@ContractBot just a plain question');
        $channel->addMessage($mention, $human);

        new RespondToMentionJob($agent, $mention)->handle();

        $mediaBlocks = [];
        foreach (CapturingNeuronProvider::$lastMessages as $message) {
            foreach ($message->getContentBlocks() as $block) {
                if ($block instanceof FileContent
                    || $block instanceof ImageContent
                    || $block instanceof AudioContent
                ) {
                    $mediaBlocks[] = $block;
                }
            }
        }

        $this->assertSame([], $mediaBlocks, 'A text-only mention must not attach any media content block');
    }

    public function testEndToEndRealHandleResolvesAndDeliversTheFileToTheAgent(): void
    {
        // Closest reproduction of the production incident: a human @mentions the agent in a comment
        // that carries a PDF. Drives the REAL mention parser (@handle → UsersAssociatedApps.displayname)
        // then the job body as the worker runs it, and asserts the PDF reaches the model.
        CapturingNeuronProvider::$lastMessages = [];

        $app = app(Apps::class);
        $human = auth()->user();

        // Unique, space-free handle so the parser's regex matches and the shared test DB can't collide.
        $agentUser = $this->makeAgentUser('ContractBot');
        $handle = 'contractbot' . $agentUser->getId();
        UsersAssociatedApps::where('users_id', $agentUser->getId())
            ->where('apps_id', $app->getId())
            ->update(['displayname' => $handle]);

        $agent = $this->makeCapturingAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, "hey @{$handle} the signed contract is attached, please review");
        $channel->addMessage($mention, $human);
        $this->attachPdf($mention, $app);

        $mentionedIds = new ParseMessageMentionsAction($mention)->execute();
        $this->assertContains(
            $agentUser->getId(),
            $mentionedIds,
            'the real mention parser must resolve the @handle to the agent user',
        );

        new RespondToMentionJob($agent, $mention)->handle();

        $sawPdf = false;
        foreach (CapturingNeuronProvider::$lastMessages as $message) {
            foreach ($message->getContentBlocks() as $block) {
                if ($block instanceof FileContent && $block->mediaType === 'application/pdf') {
                    $sawPdf = true;
                }
            }
        }

        $this->assertTrue(
            $sawPdf,
            'End-to-end: an @mention comment carrying a PDF must deliver the PDF to the model as a FileContent block',
        );
    }

    public function testChannelHistoryPreservesAPdfBlockWhenTheMentionCoalescesIntoThePriorTurn(): void
    {
        // Regression: ChannelMessageHistory coalesces consecutive same-role turns via setContents(),
        // which resets the block list to text only. A PDF on the incoming @mention (a user turn folding
        // into the prior user turn) was silently dropped before reaching the model — so a capable model
        // that CAN read the PDF still answered "I can't see the file". The media block must survive.
        $human = auth()->user();
        $channel = $this->makeChannel($human);
        // A prior human turn so the incoming mention (same USER role) coalesces into it.
        $channel->addMessage($this->makeMessage($human, 'here is some background on the deal'), $human);

        $history = new ChannelMessageHistory($channel);

        $mention = new UserMessage('please review the attached contract');
        $mention->addContent(
            new FileContent(base64_encode('%PDF-1.4 signed'), SourceType::BASE64, 'application/pdf'),
        );
        $history->addMessage($mention);

        $messages = $history->getMessages();
        $last = end($messages);

        $pdfBlocks = array_filter(
            $last->getContentBlocks(),
            static fn ($block): bool => $block instanceof FileContent && $block->mediaType === 'application/pdf',
        );

        $this->assertNotEmpty(
            $pdfBlocks,
            'A PDF on the folded-in mention must survive same-role coalescing and reach the model',
        );
        $this->assertStringContainsString(
            'review the attached contract',
            (string) $last->getContent(),
            'The mention text must still be merged into the coalesced turn',
        );
    }

    public function testListenerDelaysTheAgentReplyWhenTheMentionHasNoFileYet(): void
    {
        // The comment UI uploads attachments in a separate request that lands a few seconds after the
        // message. When the mention has no file yet, the reply must be delayed so the upload can settle.
        Bus::fake([RespondToMentionJob::class]);

        $human = auth()->user();
        $agentUser = $this->makeAgentUser('DelayBot');
        $this->makeAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, '@DelayBot can you see the file now?');
        $channel->addMessage($mention, $human);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($mention, [$agentUser->getId()]),
        );

        Bus::assertDispatched(
            RespondToMentionJob::class,
            static fn (RespondToMentionJob $job): bool => $job->delay !== null,
        );
    }

    public function testListenerDoesNotDelayWhenTheMentionAlreadyHasAFile(): void
    {
        // A mention that already carries its file (or a plain text mention) must not eat the delay.
        Bus::fake([RespondToMentionJob::class]);

        $human = auth()->user();
        $agentUser = $this->makeAgentUser('DelayBot');
        $agent = $this->makeAgent($agentUser);

        $channel = $this->makeChannel($human);
        $mention = $this->makeMessage($human, '@DelayBot here is the signed contract');
        $channel->addMessage($mention, $human);
        $this->attachPdf($mention, $agent->app);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($mention, [$agentUser->getId()]),
        );

        Bus::assertDispatched(
            RespondToMentionJob::class,
            static fn (RespondToMentionJob $job): bool => $job->delay === null,
        );
    }

    private function makeCapturingAgent(Users $agentUser): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => CapturingSystemUserAgentStub::class]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'user_id' => $agentUser->getId()]);
    }

    private function attachPdf(Message $message, Apps $app): void
    {
        // A data: URL keeps the fetch local — RunNeuronChatAction reads non-http URLs with
        // file_get_contents(), so no network / SSRF fetcher is involved and the bytes sniff as a PDF.
        $bytes = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";

        $file = new Filesystem();
        $file->apps_id = $app->getId();
        $file->companies_id = $message->companies_id;
        $file->users_id = $message->users_id;
        $file->name = 'signed-contract.pdf';
        $file->path = 'contracts/signed-contract.pdf';
        $file->url = 'data://application/pdf;base64,' . base64_encode($bytes);
        $file->file_type = 'pdf';
        $file->size = strlen($bytes);
        $file->is_deleted = 0;
        $file->saveOrFail();

        $message->addFile($file, 'attachment');
    }
}
