<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Actions\AttachEmailAttachmentsToLeadAction;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Webhooks\AgentProcessEmailWebhookJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class AttachEmailAttachmentsToLeadActionTest extends TestCase
{
    private const string SIGNING_KEY = 'test-mailgun-webhook-signing-key';

    private ReceiverWebhook $receiver;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        $this->company = $user->getCurrentCompany();
        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, self::SIGNING_KEY);
        // No app-level key — verification resolves the per-company key set above.
        app(Apps::class)->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, '');

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => AgentProcessEmailWebhookJob::class],
            ['name' => 'AgentProcessEmailWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app(app(Apps::class)->getId())
            ->user($user->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => ['capture_files' => true],
            ]);
    }

    public function testAttachesRealAttachmentToLeadAndSkipsInlineImage(): void
    {
        $lead = $this->createLead();

        // content-id-map flags attachment-1, and the body referencing it as `cid:` is what confirms
        // it is the inline signature image rather than something the sender attached.
        $webhookCall = $this->buildWebhookCall(
            [
                'sender' => 'customer@example.com',
                'attachment-count' => '2',
                'content-id-map' => json_encode([
                    '<image001.png@01DCE907.D2C23CA0>' => 'attachment-1',
                ]),
                'body-html' => '<p>Worksheet attached</p>'
                    . '<img src="cid:image001.png@01DCE907.D2C23CA0">',
            ],
            [
                'attachment-1' => UploadedFile::fake()->image('image001.png'),
                'attachment-2' => UploadedFile::fake()->create('worksheet.pdf', 40, 'application/pdf'),
            ]
        );

        $this->assertCount(2, $webhookCall->payload['uploaded_files']);

        $attached = new AttachEmailAttachmentsToLeadAction($webhookCall, $lead)->execute();

        $this->assertSame(['worksheet.pdf'], $attached);
        $this->assertCount(1, $lead->getFiles());
    }

    /**
     * Gmail gives every attachment a Content-ID whether it is embedded or not, so the map on its own
     * threw away files senders had attached on purpose.
     */
    public function testAttachesAFileGmailGaveAContentIdButNeverReferencedInTheBody(): void
    {
        $lead = $this->createLead();

        $webhookCall = $this->buildWebhookCall(
            [
                'sender' => 'customer@example.com',
                'attachment-count' => '1',
                'content-id-map' => json_encode(['<f_mt3wwiwb0>' => 'attachment-1']),
                'body-html' => '<div dir="ltr"><p>Here is the photo</p></div>',
            ],
            ['attachment-1' => UploadedFile::fake()->image('press-photo.jpg')]
        );

        $attached = new AttachEmailAttachmentsToLeadAction($webhookCall, $lead)->execute();

        $this->assertSame(['press-photo.jpg'], $attached);
        $this->assertCount(1, $lead->getFiles());
    }

    public function testReturnsEmptyWhenEmailHasNoAttachments(): void
    {
        $lead = $this->createLead();
        $webhookCall = $this->buildWebhookCall(['sender' => 'customer@example.com'], []);

        $this->assertArrayNotHasKey('uploaded_files', $webhookCall->payload);

        $attached = new AttachEmailAttachmentsToLeadAction($webhookCall, $lead)->execute();

        $this->assertSame([], $attached);
        $this->assertCount(0, $lead->getFiles());
    }

    public function testAttachesEveryAttachmentWhenNoneAreInline(): void
    {
        $lead = $this->createLead();
        $webhookCall = $this->buildWebhookCall(
            ['sender' => 'customer@example.com', 'attachment-count' => '2'],
            [
                'attachment-1' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
                'attachment-2' => UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf'),
            ]
        );

        $attached = new AttachEmailAttachmentsToLeadAction($webhookCall, $lead)->execute();

        $this->assertEqualsCanonicalizing(['quote.pdf', 'spec.pdf'], $attached);
        $this->assertCount(2, $lead->getFiles());
    }

    public function testDoesNotCaptureFilesWhenReceiverHasNotOptedIn(): void
    {
        $user = auth()->user();
        $optedOutReceiver = ReceiverWebhook::factory()
            ->app(app(Apps::class)->getId())
            ->user($user->getId())
            ->company($this->company->getId())
            ->create(['configuration' => []]);

        $lead = $this->createLead();

        $request = Request::create(
            'https://localhost/v1/receiver/' . $optedOutReceiver->uuid,
            'POST',
            ['sender' => 'customer@example.com', 'attachment-count' => '1'],
            [],
            ['attachment-1' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')]
        );

        $webhookCall = new ProcessWebhookAttemptAction($optedOutReceiver, $request)->execute();

        $this->assertArrayNotHasKey('uploaded_files', $webhookCall->payload);

        $attached = new AttachEmailAttachmentsToLeadAction($webhookCall, $lead)->execute();

        $this->assertSame([], $attached);
        $this->assertCount(0, $lead->getFiles());
    }

    public function testDoesNotCaptureFilesWhenSignatureIsInvalid(): void
    {
        $lead = $this->createLead();

        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            [
                'sender' => 'customer@example.com',
                'attachment-count' => '1',
                'timestamp' => (string) time(),
                'token' => bin2hex(random_bytes(16)),
                'signature' => 'this-is-not-a-valid-signature',
            ],
            [],
            ['attachment-1' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')]
        );

        $webhookCall = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        $this->assertArrayNotHasKey('uploaded_files', $webhookCall->payload);

        $attached = new AttachEmailAttachmentsToLeadAction($webhookCall, $lead)->execute();

        $this->assertSame([], $attached);
        $this->assertCount(0, $lead->getFiles());
    }

    private function createLead(): Lead
    {
        $user = auth()->user();

        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($user->getId())
            ->create();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, UploadedFile> $files
     */
    private function buildWebhookCall(array $payload, array $files): ReceiverWebhookCall
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            array_merge($this->signatureFields(), $payload),
            [],
            $files
        );

        return new ProcessWebhookAttemptAction($this->receiver, $request)->execute();
    }

    /**
     * A valid Mailgun signature so ProcessWebhookAttemptAction's auth gate lets the capture through.
     *
     * @return array<string, string>
     */
    private function signatureFields(): array
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));

        return [
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp . $token, self::SIGNING_KEY),
        ];
    }
}
