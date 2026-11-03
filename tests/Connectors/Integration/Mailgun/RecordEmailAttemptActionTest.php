<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Actions\SendAsAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\MessageAttempt;
use Tests\TestCase;

/**
 * The send side of email delivery tracking: what Mailgun accepted is filed under its Message-Id, and
 * the send result links that attempt to the message written afterwards.
 */
final class RecordEmailAttemptActionTest extends TestCase
{
    use DatabaseTransactions;

    private const string DOMAIN = 'agents.kanvas.test';

    protected array $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private string $mailgunId;

    /** @var array{0: array<string, mixed>, 1: int} what the send endpoint answers; a test swaps it for a refusal */
    private array $sendResponse;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reason as AgentMailboxOutboundLeadEmailTest: credentials on config, never on the shared app.
        config([
            'services.mailgun.secret' => 'key-test',
            'services.mailgun.domain' => self::DOMAIN,
        ]);

        $this->mailgunId = 'sent-' . Str::lower(Str::random(12)) . '@' . self::DOMAIN;
        $this->sendResponse = [['id' => '<' . $this->mailgunId . '>'], 200];
        Http::fake([
            'api.mailgun.net/v3/*/messages' => fn () => Http::response(...$this->sendResponse),
            '*' => Http::response([]),
        ]);
    }

    public function testAMailboxSendFilesAnAcceptedAttemptOnTheMessageItShips(): void
    {
        $agent = $this->agentWithMailbox();
        $message = $this->createMessage();

        $returned = new SendAsAgentMailboxAction(
            agent: $agent,
            to: 'prospect@example.com',
            subject: 'Your quote',
            markdownBody: 'Here is the quote.',
            trackingMessage: $message,
        )->execute();

        $this->assertSame('<' . $this->mailgunId . '>', $returned);

        $attempt = $this->attempt();
        $this->assertSame($message->getId(), (int) $attempt->message_id);
        $this->assertSame('accepted', $attempt->current_status);
        $this->assertSame('prospect@example.com', $attempt->to_address);
        $this->assertNotNull($attempt->sent_at);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('mailgun', $delivery['provider']);
        $this->assertSame('accepted', $delivery['status']);
        $this->assertSame($this->mailgunId, $delivery['message_sid']);
    }

    public function testTheSendResultLinksTheAttemptToTheMessageWrittenAfterwards(): void
    {
        new SendAsAgentMailboxAction(
            agent: $this->agentWithMailbox(),
            to: 'prospect@example.com',
            subject: 'Your quote',
            markdownBody: 'Here is the quote.',
        )->execute();
        $this->assertNull($this->attempt()->message_id);

        $message = $this->createMessage();
        new StoreMessageSidAction($message)->execute([
            'channel' => 'email',
            'provider' => 'mailgun',
            'provider_message_id' => $this->mailgunId,
        ]);

        $this->assertSame($message->getId(), (int) $this->attempt()->message_id);
        $this->assertSame(
            0,
            MessageAttempt::query()->where('message_id', $message->getId())->where('provider', 'twilio')->count(),
        );
    }

    public function testLinkingCopiesTheAcceptedStateOntoTheMessage(): void
    {
        new SendAsAgentMailboxAction(
            agent: $this->agentWithMailbox(),
            to: 'prospect@example.com',
            subject: 'Your quote',
            markdownBody: 'Here is the quote.',
        )->execute();
        $message = $this->createMessage();

        new StoreMessageSidAction($message)->execute([
            'channel' => 'email',
            'provider' => 'mailgun',
            'provider_message_id' => $this->mailgunId,
        ]);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('mailgun', $delivery['provider']);
        $this->assertSame('accepted', $delivery['status']);
        $this->assertSame($this->mailgunId, $delivery['message_sid']);
    }

    public function testASendMailgunRefusesIsFiledAsFailedAndStillThrows(): void
    {
        $this->sendResponse = [['message' => "'to' parameter is not a valid address"], 400];
        $message = $this->createMessage();
        $attemptUuid = (string) Str::uuid();

        try {
            new SendAsAgentMailboxAction(
                agent: $this->agentWithMailbox(),
                to: 'not-an-address',
                subject: 'Your quote',
                markdownBody: 'Here is the quote.',
                trackingMessage: $message,
                attemptUuid: $attemptUuid,
            )->execute();
            $this->fail('A refused send must still throw to its caller');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
        }

        $attempt = MessageAttempt::query()->where('uuid', $attemptUuid)->firstOrFail();
        $this->assertSame('mailgun', $attempt->provider);
        $this->assertSame('failed', $attempt->current_status);
        $this->assertNull($attempt->provider_message_id);
        $this->assertStringContainsString('not a valid address', (string) $attempt->last_error_message);
        $this->assertNotNull($attempt->terminal_at);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('failed', $delivery['status']);
        $this->assertStringContainsString('not a valid address', (string) $delivery['error_message']);
    }

    public function testAFailedSendResultFindsItsAttemptByUuid(): void
    {
        $this->sendResponse = [['message' => 'Domain not verified'], 400];
        $attemptUuid = (string) Str::uuid();

        try {
            new SendAsAgentMailboxAction(
                agent: $this->agentWithMailbox(),
                to: 'prospect@example.com',
                subject: 'Your quote',
                markdownBody: 'Here is the quote.',
                attemptUuid: $attemptUuid,
            )->execute();
        } catch (ValidationException) {
        }

        $message = $this->createMessage();
        new StoreMessageSidAction($message)->execute([
            'status' => 'error',
            'success' => false,
            'channel' => 'email',
            'provider' => 'mailgun',
            'attempt_uuid' => $attemptUuid,
            'error' => 'Mailgun could not send mail',
        ]);

        $this->assertSame($message->getId(), (int) MessageAttempt::query()->where('uuid', $attemptUuid)->value('message_id'));
        $this->assertSame('failed', $message->fresh()->getMessage()['delivery']['status']);
    }

    public function testAnEmailResultNoLongerFilesAFailedTwilioAttempt(): void
    {
        $message = $this->createMessage();

        new StoreMessageSidAction($message)->execute([
            'channel' => 'email',
            'to' => 'prospect@example.com',
            'template' => 'first-time-agent-engagement',
        ]);

        $this->assertSame(0, MessageAttempt::query()->where('message_id', $message->getId())->count());
        $this->assertArrayNotHasKey('delivery', $message->fresh()->getMessage());
    }

    private function attempt(): MessageAttempt
    {
        return MessageAttempt::query()
            ->where('provider', 'mailgun')
            ->where('provider_message_id', $this->mailgunId)
            ->firstOrFail();
    }

    private function createMessage(): Message
    {
        $user = auth()->user();

        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'users_id' => $user->getId(),
                'message' => ['content' => 'Here is the quote.'],
            ]);
    }

    private function agentWithMailbox(): Agent
    {
        $user = auth()->user();
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'name' => 'Sofia' . Str::random(8),
                'user_id' => $user->getId(),
            ]);
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, Str::slug($agent->name) . '@' . self::DOMAIN);

        return $agent;
    }
}
