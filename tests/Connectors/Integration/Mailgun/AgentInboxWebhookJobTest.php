<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Webhooks\AgentInboxWebhookJob;
use Kanvas\Connectors\WordPress\Actions\PushMessageToWordPressAction;
use Kanvas\Connectors\WordPress\Activities\PushMessageToWordPressActivity;
use Kanvas\Connectors\WordPress\DataTransferObject\WordPressPost;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum as WordPressConfigurationEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\Stubs\Intelligence\StructuredNeuronAgentStub;
use Tests\TestCase;

final class AgentInboxWebhookJobTest extends TestCase
{
    use HasIntegrationCompany;

    private const string DOMAIN = 'agents.kanvas.test';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private Agent $agent;
    private ReceiverWebhook $receiver;
    private string $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $this->company->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $this->company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user->getId());
        // Company settings survive a rollback: a leaked APPROVAL from another suite would lock the
        // reply as a draft and never send it.
        $this->company->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::FULL_ON->value);

        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $this->agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'name' => 'Sofia ' . Str::random(5),
                'user_id' => $this->user->getId(),
                'agent_type_id' => $agentType->getId(),
            ]);

        $this->mailbox = $this->agent->slug . '@' . self::DOMAIN;
        $this->agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $this->mailbox);
        $this->agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, MailboxAccessEnum::RESTRICTED->value);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => AgentInboxWebhookJob::class],
            ['name' => 'AgentInboxWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->user->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    ReceiverConfigurationEnum::AGENT_ID->value => $this->agent->getId(),
                    ReceiverConfigurationEnum::MAILBOX_ADDRESS->value => $this->mailbox,
                    ReceiverConfigurationEnum::CAPTURE_FILES->value => true,
                ],
            ]);
    }

    public function testATeammateEmailIsAnsweredFromTheAgentsOwnAddress(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => $this->user->email,
            'from' => 'Max <' . $this->user->email . '>',
            'subject' => 'Where are we on the Acme deal?',
            'stripped-text' => 'Where are we on the Acme deal?',
        ]);

        $this->assertStringContainsString('Hola Mundo', (string) ($result['response'] ?? ''));

        $inbound = Message::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->whereJsonContains('message->from_email', $this->user->email)
            ->latest('id')
            ->first();

        $this->assertNotNull($inbound);
        // A teammate writing in is the entity — same shape as a Slack DM, and what lets the agent
        // read who it is talking to.
        $this->assertInstanceOf(Users::class, $inbound->entity());
        $this->assertSame($this->user->getId(), $inbound->entity()->getId());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/' . self::DOMAIN . '/messages')
            && str_contains((string) $request->body(), $this->mailbox)
            && str_contains((string) $request->body(), 'Re: Where are we on the Acme deal?'));
    }

    public function testTheReplyCarriesThreadingHeadersSoItLandsInTheSameThread(): void
    {
        $this->fakeMailgun();
        // Randomized: the delivery dedupe cache outlives the test run, so a fixed id makes this
        // pass alone and fail the moment the file is run twice inside the TTL.
        $messageId = '<thread-' . Str::random(8) . '@mail.gmail.test>';

        $this->deliver([
            'sender' => $this->user->email,
            'subject' => 'Status',
            'stripped-text' => 'Any news?',
            'Message-Id' => $messageId,
        ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/messages')
            && str_contains((string) $request->body(), 'h:In-Reply-To')
            && str_contains((string) $request->body(), $messageId));
    }

    public function testAnAttachedFileLandsOnTheMessageAndSkipsTheSignatureLogo(): void
    {
        $this->fakeMailgun();

        // The uploads are injected as ProcessWebhookAttemptAction would have left them. Driving the
        // real capture would need a Mailgun signature, and the signing key lives on the shared
        // app/company that sibling test classes rewrite from other paratest processes.
        $this->deliver([
            'sender' => $this->user->email,
            'subject' => 'The signed contract',
            'stripped-text' => 'Here is the contract, can you summarize it?',
            // Mailgun maps each content-id to its multipart field; the body referencing that id as
            // `cid:` is what makes it part of the layout rather than something the sender attached.
            'content-id-map' => json_encode(['<logo@corp>' => 'attachment-2']),
            'body-html' => '<p>Here is the contract, can you summarize it?</p>'
                . '<img src="cid:logo@corp" alt="Corp">',
            'uploaded_files' => [
                [
                    'filesystem_id' => $this->uploadFile(UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf')),
                    'name' => 'contract.pdf',
                    'field' => 'attachment-1',
                ],
                [
                    'filesystem_id' => $this->uploadFile(UploadedFile::fake()->image('signature-logo.png')),
                    'name' => 'signature-logo.png',
                    'field' => 'attachment-2',
                ],
            ],
        ]);

        $message = Message::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->whereJsonContains('message->from_email', $this->user->email)
            ->latest('id')
            ->first();

        $names = $message->files->pluck('name')->all();

        $this->assertContains('contract.pdf', $names);
        // The sender's email signature is not an attachment — treating it as one buries the real
        // file under corporate logos on every single reply.
        $this->assertNotContains('signature-logo.png', $names);

        // Stored as a document, not an image — what any later reader (caption backfill, a tool, the
        // UI) keys off to decide how to open it.
        $this->assertNotEmpty($message->attachmentUrls()['documents']);
    }

    /**
     * Gmail stamps a Content-ID on EVERY attachment it sends, so `content-id-map` alone said "inline"
     * about a photo a newsroom had deliberately attached and the file was dropped on the floor — no
     * exception, nothing in Sentry, just a post with no image. Only a `cid:` reference in the body
     * makes one inline.
     */
    public function testAnAttachmentGmailGaveAContentIdToIsStillKept(): void
    {
        $this->fakeMailgun();

        $this->deliver([
            'sender' => $this->user->email,
            'subject' => 'Press release with photo',
            'stripped-text' => 'The minister confirmed the reform will continue. Photo attached.',
            // Gmail's `f_…` id for a plain attached file, and a body that never references it.
            'content-id-map' => json_encode(['<f_mt3wwiwb0>' => 'attachment-1']),
            'body-html' => '<div dir="ltr"><p>The minister confirmed the reform will continue.</p></div>',
            'uploaded_files' => [
                [
                    'filesystem_id' => $this->uploadFile(UploadedFile::fake()->image('press-photo.jpg')),
                    'name' => 'press-photo.jpg',
                    'field' => 'attachment-1',
                ],
            ],
        ]);

        $this->assertContains('press-photo.jpg', $this->inboundMessage()->files->pluck('name')->all());
    }

    /**
     * The publisher skips inbound messages by design, so the post is built from the agent's reply —
     * which owned no files at all. The newsroom's photo has to ride across for it to ever become a
     * featured image.
     */
    public function testTheAgentsReplyInheritsTheEmailsPhotoForTheWordPressPost(): void
    {
        $this->fakeMailgunAndWordPress();
        $this->useStructuredAgent();

        $this->deliver([
            'sender' => $this->user->email,
            'subject' => 'Press release with photo',
            'stripped-text' => 'The minister confirmed the reform will continue. Photo attached.',
            'Message-Id' => '<photo-' . Str::random(8) . '@mail.gmail.test>',
            'uploaded_files' => [
                [
                    'filesystem_id' => $this->uploadFile(UploadedFile::fake()->image('press-photo.jpg')),
                    'name' => 'press-photo.jpg',
                    'field' => 'attachment-1',
                ],
            ],
        ]);

        $reply = $this->agentReply();

        $this->assertNotNull($reply, 'The agent reply must be persisted');
        $this->assertContains('press-photo.jpg', $reply->files->pluck('name')->all());

        $featured = WordPressPost::fromMessage($reply)->featuredImageUrl;

        $this->assertNotNull($featured, 'The carried-forward photo must become the post featured image');
        $this->assertContains($featured, $reply->attachmentUrls()['images']);
    }

    public function testAStrangerIsTurnedAwayWhenTheMailboxIsRestricted(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => 'stranger-' . Str::random(6) . '@outside.test',
            'stripped-text' => 'hi',
        ]);

        $this->assertStringContainsString('not known to this company', $result['message']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/messages'));
    }

    public function testAnOpenMailboxCapturesAnUnknownSenderAsAContact(): void
    {
        $this->fakeMailgun();
        $this->agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, MailboxAccessEnum::OPEN->value);
        $stranger = 'lead-' . Str::random(6) . '@outside.test';

        $this->deliver([
            'sender' => $stranger,
            'from' => 'Jane Prospect <' . $stranger . '>',
            'subject' => 'Pricing',
            'stripped-text' => 'What does it cost?',
        ]);

        $people = PeoplesRepository::getByEmail($stranger, $this->company, $this->kanvasApp);
        $this->assertNotNull($people, 'An open mailbox is an inbound funnel — the sender becomes a contact.');
        $this->assertSame('Jane Prospect', $people->name);
    }

    public function testTheAgentsOwnMailIsIgnored(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => $this->mailbox,
            'stripped-text' => 'my own reply echoed back',
        ]);

        // Without this the agent answers itself, forever, on the customer's Mailgun bill.
        $this->assertSame('Message from the agent itself, ignored', $result['message']);
    }

    public function testAnAutoReplyIsIgnored(): void
    {
        $this->fakeMailgun();

        $result = $this->deliver([
            'sender' => $this->user->email,
            'stripped-text' => 'I am out of the office until Monday.',
            'Auto-Submitted' => 'auto-replied',
        ]);

        $this->assertSame('Auto-reply ignored', $result['message']);
    }

    public function testTheSameMessageIdIsOnlyAnsweredOnce(): void
    {
        $this->fakeMailgun();
        $messageId = '<dupe-' . Str::random(6) . '@mail.test>';

        $payload = [
            'sender' => $this->user->email,
            'subject' => 'Ping',
            'stripped-text' => 'ping',
            'Message-Id' => $messageId,
        ];

        $this->deliver($payload);
        $result = $this->deliver($payload);

        // Mailgun retries a forward it doesn't see acked, and a reply-all with the address cc'd
        // delivers the same Message-Id twice.
        $this->assertSame('Duplicate delivery', $result['message']);
    }

    /**
     * The newsroom flow: a press release is emailed to the agent's address, the agent rewrites it as
     * a post, and the WordPress connector publishes THAT — not the email it came from.
     *
     * The publish is driven directly rather than through the workflow rule; the rule dispatch is
     * covered by PushMessageToWordPressActivityTest. What this proves is the hand-off: the reply
     * text stays prose for the email while the record survives on the message.
     */
    public function testAPressReleaseEmailedToTheAgentBecomesAWordPressPost(): void
    {
        $this->fakeMailgunAndWordPress();
        $this->useStructuredAgent();
        $this->configureWordPress();

        $this->deliver([
            'sender' => $this->user->email,
            'from' => 'Alexander Mateo <' . $this->user->email . '>',
            'subject' => 'NT 08',
            'stripped-text' => 'Classroom construction for the new school year is moving ahead quickly in '
                . "El Seibo and La Romana\n\nSanto Domingo, RD.- The government, through the School "
                . 'Infrastructure Directorate (DIE), ordered work accelerated on dozens of new classrooms '
                . 'in the El Seibo and La Romana provinces.',
            'Message-Id' => '<press-' . Str::random(8) . '@mail.gmail.test>',
        ]);

        $reply = $this->agentReply();

        $this->assertNotNull($reply, 'The agent reply must be persisted');
        // The email body is prose — the record the agent wrote rides alongside it, not inside it.
        $this->assertSame('Hola Mundo', (string) $reply->message['content']);
        $this->assertIsArray($reply->message['response_json'] ?? null);

        new PushMessageToWordPressAction($reply)->execute();

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/wp/v2/posts')) {
                return false;
            }

            $body = $request->data();

            return $body['title'] === 'Education accelerates classroom construction in El Seibo'
                && $body['content'] === 'Hola Mundo'
                && $body['excerpt'] === 'Short summary'
                && $body['status'] === 'draft'
                && $body['categories'] === [7, 8]
                && $body['tags'] === [21, 22];
        });
    }

    /**
     * The inbound email carries the same `mailgun-email` type as the reply, so only the direction
     * flag keeps the press release itself from being published as a post.
     */
    public function testTheInboundEmailItselfIsNotPublished(): void
    {
        $this->fakeMailgunAndWordPress();
        $this->useStructuredAgent();
        $this->configureWordPress();
        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::WORDPRESS,
            'Kanvas\\Connectors\\WordPress\\Handlers\\WordPressHandler',
            $this->company,
            $this->user
        );

        $this->deliver([
            'sender' => $this->user->email,
            'subject' => 'NT 09',
            'stripped-text' => 'Construcción de nuevas aulas para el nuevo año escolar',
            'Message-Id' => '<press-' . Str::random(8) . '@mail.gmail.test>',
        ]);

        $inbound = Message::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->whereJsonContains('message->from_email', $this->user->email)
            ->latest('id')
            ->first();

        $activity = new PushMessageToWordPressActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($inbound, $this->kanvasApp, []);

        $this->assertStringContainsString('Inbound message', $result['message']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/wp/v2/posts'));
    }

    private function useStructuredAgent(): void
    {
        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => StructuredNeuronAgentStub::class,
            ]);

        $this->agent->agent_type_id = $agentType->getId();
        $this->agent->saveOrFail();
    }

    private function configureWordPress(): void
    {
        $this->company->set(WordPressConfigurationEnum::SITE_URL->value, 'https://example.com');
        $this->company->set(WordPressConfigurationEnum::USERNAME->value, 'editor');
        $this->company->set(WordPressConfigurationEnum::APPLICATION_PASSWORD->value, 'abcd efgh ijkl mnop');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function deliver(array $payload): array
    {
        // Set here rather than in setUp: MailgunHandlerTest deletes this key from the shared app in
        // its own tearDown, and paratest runs the two classes concurrently. Writing it immediately
        // before the send leaves no window worth racing.
        $this->kanvasApp->set(ConfigurationEnum::API_KEY->value, 'key-test');

        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();
        $result = new AgentInboxWebhookJob($webhookRequest)->handle();

        if (! is_array($result)) {
            // ProcessWebhookJob swallows throwables into Sentry and returns null — without this the
            // real message is lost and the failure surfaces as an unrelated TypeError.
            $this->fail('Job failed: ' . json_encode($webhookRequest->refresh()->exception['message'] ?? 'unknown'));
        }

        return $result;
    }

    /**
     * `from_ia` alone matches every agent reply in the app, and paratest runs sibling classes
     * against that same app — the newest one is as likely to be another process's as this test's.
     * The agent is created per test, so its id is what makes the lookup this test's own.
     */
    private function agentReply(): ?Message
    {
        return Message::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->whereJsonContains('message->from_ia', true)
            ->whereJsonContains('message->agent_id', (int) $this->agent->getId())
            ->latest('id')
            ->first();
    }

    private function inboundMessage(): Message
    {
        return Message::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->whereJsonContains('message->from_email', $this->user->email)
            ->latest('id')
            ->firstOrFail();
    }

    private function uploadFile(UploadedFile $file): int
    {
        return new FilesystemServices($this->kanvasApp, $this->company)
            ->upload($file, $this->user)
            ->getId();
    }

    private function fakeMailgun(): void
    {
        Http::fake([
            'api.mailgun.net/v3/domains/*' => Http::response(['domain' => ['name' => self::DOMAIN]]),
            'api.mailgun.net/v3/*/messages' => Http::response(['id' => '<queued@' . self::DOMAIN . '>']),
            '*' => Http::response([]),
        ]);
    }

    /**
     * Mailgun plus a wp/v2 site on one fake, because the publish runs inside the same delivery.
     * Categories resolve by search; tags come back empty so they take the create branch.
     */
    private function fakeMailgunAndWordPress(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $isRead = $request->method() === 'GET';
            $searched = urldecode((string) ($request->data()['search'] ?? ''));

            return match (true) {
                str_contains($url, 'api.mailgun.net/v3/domains/') => Http::response(
                    ['domain' => ['name' => self::DOMAIN]]
                ),
                str_ends_with($path, '/messages') => Http::response(['id' => '<queued@' . self::DOMAIN . '>']),
                str_ends_with($path, '/wp/v2/categories') && $isRead => Http::response(
                    [['id' => $searched === 'National' ? 7 : 8, 'name' => $searched]]
                ),
                str_ends_with($path, '/wp/v2/tags') && $isRead => Http::response([]),
                str_ends_with($path, '/wp/v2/tags') => Http::response(
                    ['id' => ($request->data()['name'] ?? '') === 'Education' ? 21 : 22],
                    201
                ),
                str_ends_with($path, '/wp/v2/posts') => Http::response(
                    [
                        'id' => 101,
                        'link' => 'https://example.com/?p=101',
                        'status' => 'draft',
                        'categories' => [7, 8],
                        'tags' => [21, 22],
                    ],
                    201
                ),
                default => Http::response([]),
            };
        });
    }
}
