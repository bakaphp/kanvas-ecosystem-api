<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\CapturingNeuronProvider;
use Tests\TestCase;

/**
 * A user drops a PDF in Slack → the connector must download it with the bot token, re-host it on the
 * message, and forward it to the agent so the agent can actually read it. Before this the Slack
 * connector ignored `event['files']` entirely and the agent never saw attachments.
 */
final class SlackInboundAttachmentTest extends TestCase
{
    private const string BOT_USER_ID = 'UBOT123';
    private const string DM_CHANNEL = 'DFILE1';
    private const string BOT_TOKEN = 'xoxb-test-token';

    private string $teamId;
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private Agent $agent;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        CapturingNeuronProvider::$lastMessages = [];

        $this->teamId = 'T' . strtoupper(Str::random(6));
        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $this->company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user->getId());

        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => CapturingNeuronAgentStub::class,
            ]);

        $this->agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'user_id' => $this->user->getId(),
                'agent_type_id' => $agentType->getId(),
            ]);

        $this->agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, self::BOT_TOKEN);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackWebhookJob::class],
            ['name' => 'ProcessSlackWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->user->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    ConfigurationEnum::AGENT_ID->value => $this->agent->getId(),
                    ConfigurationEnum::BOT_USER_ID->value => self::BOT_USER_ID,
                    ConfigurationEnum::SIGNING_SECRET->value => 'shhh',
                ],
            ]);
    }

    public function testInboundPdfIsDownloadedWithTheBotTokenAndForwardedToTheAgent(): void
    {
        $fileUrl = 'https://files.slack.com/files-pri/' . $this->teamId . '-FPDF1/download/report.pdf';

        Http::fake([
            'slack.com/api/users.info' => Http::response([
                'ok' => true,
                'user' => ['profile' => ['email' => $this->user->email]],
            ]),
            'files.slack.com/*' => Http::response(
                $this->pdfBytes(),
                200,
                ['Content-Type' => 'application/pdf'],
            ),
            'slack.com/api/*' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);

        $this->dispatch($this->messageEvent([
            'channel' => self::DM_CHANNEL,
            'channel_type' => 'im',
            'text' => 'please review this contract',
            'files' => [[
                'id' => 'FPDF1',
                'name' => 'report.pdf',
                'mimetype' => 'application/pdf',
                'filetype' => 'pdf',
                'url_private_download' => $fileUrl,
                'url_private' => $fileUrl,
            ]],
        ]));

        // The private Slack URL was fetched with the bot token as a bearer header — a plain GET would
        // have returned Slack's HTML login page.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'files.slack.com')
            && $request->hasHeader('Authorization', 'Bearer ' . self::BOT_TOKEN));

        $inbound = $this->messagesOn(self::DM_CHANNEL)->first();
        $this->assertNotNull($inbound);

        $files = $inbound->files;
        $this->assertCount(1, $files, 'The Slack PDF must be re-hosted as a Kanvas Filesystem entry on the message');
        $this->assertTrue(
            $files->first()->mediaType()->isDocument(),
            'A PDF must classify as a document so it rides the documents bucket to the agent',
        );

        // Part B: the responder forwarded the document to the kernel — the agent's turn text carries the
        // re-hosted URL under AttachmentPromptBuilder's "Attached files:" list. (The binary block itself
        // is covered by RunNeuronChatAttachmentTest; here we only assert the wiring reaches the agent.)
        $this->assertNotEmpty(
            CapturingNeuronProvider::$lastMessages,
            'The Neuron agent must have been invoked',
        );

        $agentText = $this->capturedUserText();
        $this->assertStringContainsString('Attached files:', $agentText);
        $this->assertStringContainsString($files->first()->url, $agentText);
    }

    private function capturedUserText(): string
    {
        $text = '';
        foreach (CapturingNeuronProvider::$lastMessages as $message) {
            $text .= ' ' . (string) $message->getContent();
        }

        return $text;
    }

    private function messagesOn(string $slackChannelId): Collection
    {
        $channel = Channel::where(
            'slug',
            'slack-' . strtolower($this->teamId . '-' . $slackChannelId)
        )->firstOrFail();

        return Message::whereHas('channels', fn ($query) => $query->where('channels.id', $channel->getId()))
            ->orderBy('id')
            ->get();
    }

    private function messageEvent(array $overrides = []): array
    {
        return [
            'type' => 'message',
            'user' => 'U0001',
            'channel' => self::DM_CHANNEL,
            'text' => 'hello',
            'ts' => '1700000000.000100',
            ...$overrides,
        ];
    }

    private function dispatch(array $event): array
    {
        $payload = [
            'type' => 'event_callback',
            'team_id' => $this->teamId,
            'api_app_id' => 'A0001',
            'event_id' => 'Ev' . Str::random(8),
            'event' => $event,
        ];

        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        $result = new ProcessSlackWebhookJob($webhookRequest)->handle();

        if (! is_array($result)) {
            $this->fail('Job failed: ' . json_encode($webhookRequest->refresh()->exception['message'] ?? 'unknown'));
        }

        return $result;
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
    }
}
