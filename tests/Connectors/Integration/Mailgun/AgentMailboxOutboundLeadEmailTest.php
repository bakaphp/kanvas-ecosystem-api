<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The outbound half of the agent mailbox: an agent that owns an address writes to the prospect from
 * it, instead of the company's SMTP identity. The reply lane already did this; these cover the sends
 * the agent starts — send_email, follow-ups and first-touch outreach.
 */
final class AgentMailboxOutboundLeadEmailTest extends TestCase
{
    private const string DOMAIN = 'agents.kanvas.test';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    /**
     * Credentials go on `config`, not on the app or the company: LeadObserver creates a channel and
     * needs Bouncer roles, which only the seeded app has, so the lead cannot move to an app of its
     * own — and writing Mailgun settings onto the SHARED app/company would leak into every sibling
     * paratest process. `services.mailgun.*` is the documented last-resort fallback for both, and it
     * dies with the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->kanvasApp = app(Apps::class);

        config([
            'services.mailgun.secret' => 'key-test',
            'services.mailgun.domain' => self::DOMAIN,
        ]);

        Http::fake([
            'api.mailgun.net/v3/*/messages' => Http::response(['id' => '<sent@' . self::DOMAIN . '>']),
            '*' => Http::response([]),
        ]);
    }

    /**
     * A sibling process may have set a real domain on the shared app/company, which outranks the
     * config fallback — so the URL assertion asks the service which domain actually won rather than
     * assuming ours did. The From address is unaffected: this test writes the mailbox itself.
     */
    private function sendingDomain(Agent $agent): string
    {
        return new AgentMailboxService()->domainFor($agent);
    }

    public function testAnAgentWithAMailboxSendsFromItsOwnAddress(): void
    {
        Notification::fake();
        $agent = $this->agentWithMailbox();
        $lead = $this->leadWithEmail('prospect@example.com');

        $result = new SendMessageToLeadAction($lead)->execute(
            channel: LeadCommunicationChannelEnum::EMAIL->value,
            message: 'Here is the quote you asked for.',
            title: 'Your quote',
            to: 'prospect@example.com',
            fromAgent: $agent,
        );

        $this->assertSame($this->addressOf($agent), $result['from']);
        $this->assertSame('agent-mailbox', $result['template']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v3/' . $this->sendingDomain($agent) . '/messages')
            && str_contains((string) $request->body(), $this->addressOf($agent))
            && str_contains((string) $request->body(), 'prospect@example.com'));

        // The company SMTP identity is the thing being replaced, not doubled up on.
        Notification::assertNothingSent();
    }

    public function testTheAgentAddressIsAlsoTheReplyTo(): void
    {
        $agent = $this->agentWithMailbox();
        $lead = $this->leadWithEmail('prospect@example.com');

        new SendMessageToLeadAction($lead)->execute(
            channel: LeadCommunicationChannelEnum::EMAIL->value,
            message: 'Here is the quote.',
            title: 'Your quote',
            to: 'prospect@example.com',
            fromAgent: $agent,
        );

        // Without this the prospect's answer goes to the company inbox and the agent never sees it.
        Http::assertSent(fn ($request): bool => str_contains((string) $request->body(), 'h:Reply-To')
            && str_contains((string) $request->body(), $this->addressOf($agent)));
    }

    public function testCcSurvivesTheAgentLane(): void
    {
        $agent = $this->agentWithMailbox();
        $lead = $this->leadWithEmail('prospect@example.com');

        $result = new SendMessageToLeadAction($lead)->execute(
            channel: LeadCommunicationChannelEnum::EMAIL->value,
            message: 'Here is the quote.',
            title: 'Your quote',
            to: 'prospect@example.com',
            cc: ['spouse@example.com'],
            fromAgent: $agent,
        );

        $this->assertSame(['spouse@example.com'], $result['cc']);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->body(), 'name="cc"')
            && str_contains((string) $request->body(), 'spouse@example.com'));
    }

    public function testAnAgentWithoutAMailboxStillSendsOnTheCompanyIdentity(): void
    {
        Notification::fake();
        $agent = $this->agent();
        $lead = $this->leadWithEmail('prospect@example.com');

        $result = new SendMessageToLeadAction($lead)->execute(
            channel: LeadCommunicationChannelEnum::EMAIL->value,
            message: 'Here is the quote.',
            title: 'Your quote',
            to: 'prospect@example.com',
            fromAgent: $agent,
        );

        $this->assertSame('first-time-agent-engagement', $result['template']);
        Notification::assertSentOnDemand(Blank::class);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/messages'));
    }

    public function testSendEmailToolUsesTheActingAgentsMailbox(): void
    {
        Notification::fake();
        $agent = $this->agentWithMailbox();
        $lead = $this->leadWithEmail('prospect@example.com');

        $result = new SendEmailTool()
            ->withContext($this->kanvasApp, $this->company, $this->user, $agent)
            ->__invoke(
                lead_id: $lead->getId(),
                subject: 'Your quote',
                body: 'Here is the quote you asked for.',
            );

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->addressOf($agent), $result['from']);
        Notification::assertNothingSent();
    }

    private function leadWithEmail(string $email): Lead
    {
        $lead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();

        $lead->people->contacts()
            ->whereIn('contacts_types_id', [
                ContactTypeEnum::PRIMARY_EMAIL->value,
                ContactTypeEnum::EMAIL->value,
                ContactTypeEnum::SECONDARY_EMAIL->value,
            ])
            ->delete();
        $lead->people->addEmail($email);

        return $lead;
    }

    private function agentWithMailbox(): Agent
    {
        $agent = $this->agent();
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $this->addressOf($agent));

        return $agent;
    }

    /**
     * Names are randomized for the same reason the provisioning suite randomizes them: this runs
     * without DatabaseTransactions, so a fixed name lets an earlier run own the address.
     */
    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'name' => 'Sofia' . Str::random(8),
                'user_id' => $this->user->getId(),
            ]);
    }

    private function addressOf(Agent $agent): string
    {
        return Str::slug($agent->name) . '@' . self::DOMAIN;
    }
}
