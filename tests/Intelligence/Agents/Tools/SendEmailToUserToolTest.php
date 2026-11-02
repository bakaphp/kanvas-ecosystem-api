<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendEmailToUserTool;
use Kanvas\Intelligence\Sessions\Services\UserAgentChannelService;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SendEmailToUserToolTest extends TestCase
{
    private const string DOMAIN = 'agents.kanvas.test';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testEmailsACompanyTeammate(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool($this->makeAgent())->__invoke(
            recipient_email: $this->user->email,
            subject: 'Standup notes',
            body: 'Here is the **summary** from today.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->user->email, $result['to']);

        Mail::assertSent(
            KanvasMailable::class,
            fn (KanvasMailable $mail): bool => $mail->hasTo($this->user->email)
                && $mail->subject === 'Standup notes'
        );
    }

    public function testSentEmailIsRecordedInTheAgentLedger(): void
    {
        Mail::fake();
        $agent = $this->makeAgent();

        new SendEmailToUserTool($agent)->__invoke(
            recipient_email: $this->user->email,
            subject: 'Quarterly numbers',
            body: 'The report is attached.',
        );

        $event = Event::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('event_type', SendEmailToUserTool::LEDGER_EVENT)
            ->where('actor_type', 'Agent')
            ->where('actor_id', $agent->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'Expected the teammate email in the agent ledger');
        $this->assertSame(Users::class, $event->source_entity_type);
        $this->assertSame($this->user->getId(), (int) $event->source_entity_id);
        $this->assertSame('Quarterly numbers', $event->payload['subject']);
        $this->assertSame('The report is attached.', $event->payload['body']);
    }

    public function testSentEmailLandsInTheRecipientsChatWithTheAgent(): void
    {
        Mail::fake();
        $agent = $this->makeAgent();
        $subject = 'Contract draft ' . uniqid();

        new SendEmailToUserTool($agent)->__invoke(
            recipient_email: $this->user->email,
            subject: $subject,
            body: 'Please review section 4.',
        );

        $session = new UserAgentChannelService()->resolveSession(
            human: $this->user,
            agent: $agent,
            app: $this->kanvasApp,
            company: $this->company,
            entity: $this->user,
        );

        $posted = $session->channel->messages()->latest('messages.id')->first();
        $this->assertNotNull($posted, 'Expected the email in the agent chat channel');
        $this->assertStringContainsString($subject, $posted->contentText());

        // What the agent replays on the recipient's next turn in that chat.
        $history = new ConversationMessageStore(
            app: $this->kanvasApp,
            company: $this->company,
            user: $this->user,
            agentClass: $agent->type?->handler ?? $agent::class,
            sessionId: $session->uuid,
            agent: $agent,
        )->loadActive($session->uuid);

        $this->assertNotEmpty($history);
        $last = end($history);
        $this->assertStringContainsString($subject, (string) $last->getContent());
        $this->assertStringContainsString('Please review section 4.', (string) $last->getContent());
    }

    /**
     * The company SMTP sender is one identity for every agent of the company, so an agent with a
     * mailbox of its own has to leave through it: the teammate sees the agent's name and address,
     * and a reply lands in the agent's inbox instead of whoever the company sender is.
     */
    public function testAnAgentWithAMailboxSendsFromItsOwnAddress(): void
    {
        Mail::fake();
        $this->fakeMailgun();
        $agent = $this->makeAgent();
        $address = Str::slug($agent->name) . '@' . self::DOMAIN;
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $address);

        $result = new SendEmailToUserTool($agent)->__invoke(
            recipient_email: $this->user->email,
            subject: 'Standup notes',
            body: 'Here is the **summary** from today.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($address, $result['from']);

        $domain = new AgentMailboxService()->domainFor($agent);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v3/' . $domain . '/messages')
            && str_contains((string) $request->body(), $agent->name . ' <' . $address . '>')
            && str_contains((string) $request->body(), $this->user->email)
            && str_contains((string) $request->body(), 'h:Reply-To'));

        Mail::assertNothingSent();
    }

    public function testAnAgentWithoutAMailboxStillSendsThroughTheCompanySender(): void
    {
        Mail::fake();
        $this->fakeMailgun();

        $result = new SendEmailToUserTool($this->makeAgent())->__invoke(
            recipient_email: $this->user->email,
            subject: 'Standup notes',
            body: 'Here is the summary.',
        );

        $this->assertSame('success', $result['status']);
        Mail::assertSent(KanvasMailable::class);
        Http::assertNothingSent();
    }

    public function testRejectsAnEmailThatIsNotATeammate(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool($this->makeAgent())->__invoke(
            recipient_email: 'stranger-' . uniqid() . '@example.com',
            subject: 'Hi',
            body: 'Anyone there?',
        );

        $this->assertSame('error', $result['status']);
        Mail::assertNothingSent();
    }

    public function testErrorsWithoutAnAgentInScope(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool(null)->__invoke(
            recipient_email: $this->user->email,
            subject: 'Hi',
            body: 'Hello',
        );

        $this->assertSame('error', $result['status']);
        Mail::assertNothingSent();
    }

    public function testRejectsAnEmptySubjectOrBody(): void
    {
        Mail::fake();

        $result = new SendEmailToUserTool($this->makeAgent())->__invoke(
            recipient_email: $this->user->email,
            subject: '   ',
            body: 'Hello',
        );

        $this->assertSame('error', $result['status']);
        Mail::assertNothingSent();
    }

    /**
     * Credentials go on config, never on the shared app or company: a setting written there is
     * visible to every sibling paratest process. `services.mailgun.*` is the documented last-resort
     * fallback for both the key and the domain, and it dies with the test.
     */
    private function fakeMailgun(): void
    {
        config([
            'services.mailgun.secret' => 'key-test',
            'services.mailgun.domain' => self::DOMAIN,
        ]);

        Http::fake([
            'api.mailgun.net/v3/*/messages' => Http::response(['id' => '<sent@' . self::DOMAIN . '>']),
        ]);
    }

    private function makeAgent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Sofia ' . Str::random(8), 'user_id' => $this->user->getId()]);
    }
}
