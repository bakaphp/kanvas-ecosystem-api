<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Messages\Actions\LinkDeliveryMessageAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Mailgun\Actions\SendAsAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

/**
 * The send side of email delivery tracking: what Mailgun accepted is filed in the communication ledger
 * under its Message-Id, and the send result links that delivery to the message written afterwards.
 */
final class FileEmailDeliveryActionTest extends TestCase
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

    public function testAMailboxSendFilesAnAcceptedDelivery(): void
    {
        $agent = $this->agentWithMailbox();

        $returned = new SendAsAgentMailboxAction(
            agent: $agent,
            to: 'Teammate@Example.com',
            subject: 'Your quote',
            markdownBody: 'Here is the **quote**.',
        )->execute();

        $this->assertSame('<' . $this->mailgunId . '>', $returned);

        $delivery = $this->delivery();
        $this->assertNull($delivery->message_id);
        $this->assertSame('accepted', $delivery->status->value);
        $this->assertSame('email', $delivery->channel->value);
        $this->assertSame('teammate@example.com', $delivery->to_address);
        $this->assertNotNull($delivery->accepted_at);

        $request = $delivery->request;
        $this->assertEquals(['type' => 'agent', 'id' => $agent->getId()], $request->sender);
        $this->assertSame('Your quote', $request->content['subject']);
        $this->assertNull($request->people_id);
    }

    /**
     * The text goes out as the agent wrote it, with the mailbox as Reply-To so the answer comes
     * back to the agent.
     */
    public function testAMailboxSendLeavesAsWrittenWithTheMailboxAsReplyTo(): void
    {
        $agent = $this->agentWithMailbox();

        new SendAsAgentMailboxAction(
            agent: $agent,
            to: 'teammate@example.com',
            subject: 'Your quote',
            markdownBody: 'Here is the **quote**.',
        )->execute();

        Http::assertSent(function (HttpRequest $request) use ($agent): bool {
            $parts = collect($request->data())->pluck('contents', 'name');

            return $parts['text'] === 'Here is the **quote**.'
                && $parts['html'] === EmailComposer::writtenHtml('Here is the **quote**.')
                && str_contains((string) $parts['html'], '<strong>quote</strong>')
                && $parts['h:Reply-To'] === $agent->get(CustomFieldEnum::MAILBOX_ADDRESS->value);
        });
    }

    public function testTheSendResultLinksTheDeliveryToTheMessageWrittenAfterwards(): void
    {
        new SendAsAgentMailboxAction(
            agent: $this->agentWithMailbox(),
            to: 'prospect@example.com',
            subject: 'Your quote',
            markdownBody: 'Here is the quote.',
        )->execute();
        $this->assertNull($this->delivery()->message_id);

        $message = $this->createMessage();
        new LinkDeliveryMessageAction($message)->execute([
            'channel' => 'email',
            'provider' => 'mailgun',
            'provider_message_id' => $this->mailgunId,
        ]);

        $this->assertSame($message->getId(), (int) $this->delivery()->message_id);
        $this->assertSame('accepted', $message->fresh()->getMessage()['delivery']['status']);
        $this->assertSame(0, MessageAttempt::query()->where('message_id', $message->getId())->count());
    }

    public function testASendMailgunRefusesIsFiledAsFailedAndStillThrows(): void
    {
        $this->sendResponse = [['message' => "'to' parameter is not a valid address"], 400];
        $to = 'refused-' . Str::lower(Str::random(10)) . '@example.com';

        try {
            new SendAsAgentMailboxAction(
                agent: $this->agentWithMailbox(),
                to: $to,
                subject: 'Your quote',
                markdownBody: 'Here is the quote.',
            )->execute();
            $this->fail('A refused send must still throw to its caller');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
        }

        $delivery = Delivery::query()->where('to_address', $to)->firstOrFail();
        $this->assertSame('mailgun', $delivery->provider);
        $this->assertSame('failed', $delivery->status->value);
        $this->assertNull($delivery->external_id);
        $this->assertStringContainsString('not a valid address', (string) $delivery->error);
        $this->assertNotNull($delivery->events_closed_at);
    }

    public function testAFailedSendResultFindsItsDeliveryByUuid(): void
    {
        $this->sendResponse = [['message' => 'Domain not verified'], 400];
        $to = 'refused-' . Str::lower(Str::random(10)) . '@example.com';

        try {
            new SendAsAgentMailboxAction(
                agent: $this->agentWithMailbox(),
                to: $to,
                subject: 'Your quote',
                markdownBody: 'Here is the quote.',
            )->execute();
        } catch (ValidationException) {
        }

        $attemptUuid = (string) Delivery::query()->where('to_address', $to)->value('uuid');
        $message = $this->createMessage();
        new LinkDeliveryMessageAction($message)->execute([
            'status' => 'error',
            'success' => false,
            'channel' => 'email',
            'provider' => 'mailgun',
            'attempt_uuid' => $attemptUuid,
            'error' => 'Mailgun could not send mail',
        ]);

        $this->assertSame($message->getId(), (int) Delivery::query()->where('uuid', $attemptUuid)->value('message_id'));
        $this->assertSame('failed', $message->fresh()->getMessage()['delivery']['status']);
    }

    public function testAnEmailResultDoesNotFileAFailedTwilioAttempt(): void
    {
        $message = $this->createMessage();

        new LinkDeliveryMessageAction($message)->execute([
            'channel' => 'email',
            'to' => 'prospect@example.com',
            'template' => 'first-time-agent-engagement',
        ]);

        $this->assertSame(0, MessageAttempt::query()->where('message_id', $message->getId())->count());
        $this->assertArrayNotHasKey('delivery', $message->fresh()->getMessage());
    }

    private function delivery(): Delivery
    {
        return Delivery::query()
            ->where('provider', 'mailgun')
            ->where('external_id', $this->mailgunId)
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
