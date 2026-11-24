<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Outlook\SendOutlookEmailTool;
use Kanvas\NervousSystem\Plan\Support\ApprovalPolicy;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\TrackByInputs;
use Override;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Microsoft\Concerns\KeepsMicrosoftSettings;
use Tests\TestCase;

#[Group('serial')]
final class OutlookSendEmailToolTest extends TestCase
{
    use DatabaseTransactions;
    use KeepsMicrosoftSettings;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'workflow'];

    private Apps $kanvasApp;

    private Companies $company;

    private mixed $keptAllowedDomains;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->keepMicrosoftSettings($this->kanvasApp, $this->company);
        $this->keptAllowedDomains = $this->kanvasApp->get(LeadConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value);
        $this->kanvasApp->del(LeadConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->restoreMicrosoftSettings();
        $key = LeadConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value;
        $this->keptAllowedDomains === null
            ? $this->kanvasApp->del($key)
            : $this->kanvasApp->set($key, $this->keptAllowedDomains);

        parent::tearDown();
    }

    public function testTheFirstCallOnlyDraftsAndSendsNothing(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake();

        $result = $this->tool()->__invoke(to: $contact, subject: 'Propuesta', body: 'Adjunto la propuesta.');

        $this->assertFalse($result['sent']);
        $this->assertNotEmpty($result['draft_id']);
        $this->assertSame([$contact], $result['to']);
        $this->assertSame('Propuesta', $result['subject']);
        $this->assertSame('Adjunto la propuesta.', $result['body']);
        Http::assertNothingSent();
    }

    public function testTheModelCannotConfirmItsOwnDraftInTheSameTurn(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake();
        $turn = $this->tool();

        $draft = $turn->__invoke(to: $contact, subject: 'Hola', body: 'Hola');
        $result = $turn->__invoke(draft_id: $draft['draft_id']);

        $this->assertFalse($result['success']);
        $this->assertSame('awaiting_user_confirmation', $result['reason']);
        Http::assertNothingSent();
    }

    public function testAConfirmationOnALaterTurnSendsExactlyTheDraftOnce(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake(['graph.microsoft.com/*' => Http::response(null, 202)]);

        $draft = $this->tool()->__invoke(to: $contact, subject: 'Propuesta', body: "Hola **Ana**,\nadjunto la propuesta.");
        $result = $this->tool()->__invoke(draft_id: $draft['draft_id']);
        $again = $this->tool()->__invoke(draft_id: $draft['draft_id']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['sent']);
        $this->assertFalse($again['success']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($contact) {
            $message = $request['message'];

            return $request->url() === 'https://graph.microsoft.com/v1.0/me/sendMail'
                && $request->hasHeader('Authorization', 'Bearer stored-token')
                && $message['subject'] === 'Propuesta'
                && $message['body']['contentType'] === 'HTML'
                && str_contains($message['body']['content'], '<strong>Ana</strong>')
                && $message['toRecipients'][0]['emailAddress']['address'] === $contact;
        });
    }

    public function testAnotherPersonCannotConfirmSomeoneElsesDraft(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake();

        $draft = $this->tool()->__invoke(to: $contact, subject: 'Hola', body: 'Hola');
        $result = $this->tool(human: Users::factory()->create())->__invoke(draft_id: $draft['draft_id']);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function testRefusesATurnTheAgentRunsOnItsOwn(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake();
        $agent = $this->agent();

        $result = new SendOutlookEmailTool()
            ->withContext($this->kanvasApp, $this->company, $agent->user, $agent)
            ->__invoke(to: $contact, subject: 'Hola', body: 'Hola');

        $this->assertFalse($result['success']);
        $this->assertSame('agent_caller', $result['status']);
        Http::assertNothingSent();
    }

    public function testDraftsToATeammateAndCopiesAnAllowedDomain(): void
    {
        $this->connectMailbox();
        $this->kanvasApp->set(LeadConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value, 'partner-test.example');
        Http::fake(['graph.microsoft.com/*' => Http::response(null, 202)]);
        $agent = $this->agent();
        $teammate = static::$cachedUser->email;

        $draft = $this->tool($agent)->__invoke(to: $teammate, subject: 'Hola', body: 'Hola', cc: 'socio@partner-test.example');
        $result = $this->tool($agent)->__invoke(draft_id: $draft['draft_id']);

        $this->assertTrue($result['success']);
        Http::assertSent(
            fn (Request $request) => $request['message']['toRecipients'][0]['emailAddress']['address'] === $teammate
                && $request['message']['ccRecipients'][0]['emailAddress']['address'] === 'socio@partner-test.example'
        );
    }

    public function testRefusesTheWholeDraftWhenOneAddressIsOutsideTheClosedSet(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake();

        $result = $this->tool()->__invoke(to: $contact . ', attacker@evil-test.example', subject: 'Hola', body: 'Hola');

        $this->assertFalse($result['success']);
        $this->assertSame('recipient_not_allowed', $result['reason']);
        $this->assertSame(['attacker@evil-test.example'], $result['refused']);
        $this->assertArrayNotHasKey('draft_id', $result);
        Http::assertNothingSent();
    }

    public function testATeammateAddressIsRefusedWithoutAnAgentInContext(): void
    {
        $this->connectMailbox();
        Http::fake();

        $result = $this->tool()->__invoke(to: static::$cachedUser->email, subject: 'Hola', body: 'Hola');

        $this->assertSame('recipient_not_allowed', $result['reason']);
    }

    public function testEscapesHtmlTheModelWritesInTheBody(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake(['graph.microsoft.com/*' => Http::response(null, 202)]);

        $draft = $this->tool()->__invoke(to: $contact, subject: 'Hola', body: '<img src=x onerror=alert(1)>');
        $this->tool()->__invoke(draft_id: $draft['draft_id']);

        Http::assertSent(fn (Request $request) => ! str_contains($request['message']['body']['content'], '<img'));
    }

    public function testAnswersNotConnectedWhenTheCompanyHasNoMailbox(): void
    {
        $this->disconnectMailbox();
        $contact = $this->crmContact();
        Http::fake();

        $draft = $this->tool()->__invoke(to: $contact, subject: 'Hola', body: 'Hola');
        $result = $this->tool()->__invoke(draft_id: $draft['draft_id']);

        $this->assertFalse($result['success']);
        $this->assertSame('microsoft_not_connected', $result['reason']);
        Http::assertNothingSent();
    }

    public function testAGraphRefusalIsReportedAndTheDraftCanBeRetried(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake(['graph.microsoft.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Access is denied.']], 403)
            ->push(null, 202)]);

        $draft = $this->tool()->__invoke(to: $contact, subject: 'Hola', body: 'Hola');
        $failed = $this->tool()->__invoke(draft_id: $draft['draft_id']);
        $retried = $this->tool()->__invoke(draft_id: $draft['draft_id']);

        $this->assertSame('request_failed', $failed['reason']);
        $this->assertTrue($retried['success']);
    }

    public function testTheEmailIsRecordedOnTheCrmPersonItReached(): void
    {
        $this->connectMailbox();
        $contact = $this->crmContact();
        Http::fake(['graph.microsoft.com/*' => Http::response(null, 202)]);

        $draft = $this->tool()->__invoke(to: $contact, subject: 'Propuesta', body: 'Adjunto la propuesta.');
        $this->tool()->__invoke(draft_id: $draft['draft_id']);

        // One message, filed on both the person's email channel and their own conversation channel.
        $person = Contact::query()->where('value', $contact)->firstOrFail()->people;
        $sent = Channel::query()
            ->where('entity_namespace', People::class)
            ->where('entity_id', $person->getId())
            ->get()
            ->flatMap(fn (Channel $channel) => $channel->messages()->get())
            ->unique('id')
            ->map(fn ($message) => $message->message['content'])
            ->values()
            ->all();

        $this->assertSame(['Adjunto la propuesta.'], $sent);
    }

    public function testIsInternalOnlyGatedAndBudgetsEachDistinctEmail(): void
    {
        $this->assertInstanceOf(RequiresSystemAgent::class, new SendOutlookEmailTool());
        $this->assertContains(TrackByInputs::class, class_uses_recursive(SendOutlookEmailTool::class));
        $this->assertFalse(WorkerToolPolicy::permits('send_outlook_email'));
        $this->assertTrue(ApprovalPolicy::touchesGatedTool(['send_outlook_email']));
    }

    /**
     * A new instance stands for a new turn: the agent builds its tools once per run.
     */
    private function tool(?Agent $agent = null, ?Users $human = null): SendOutlookEmailTool
    {
        return new SendOutlookEmailTool()
            ->withContext(
                $this->kanvasApp,
                $this->company,
                static::$cachedUser,
                $agent,
            )
            ->forConversationHuman($human ?? static::$cachedUser);
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();
    }

    private function crmContact(): string
    {
        $email = 'contact-' . uniqid() . '@example.com';
        $person = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();

        Contact::factory()->create([
            'peoples_id' => $person->getId(),
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => $email,
        ]);

        return $email;
    }

    private function connectMailbox(): void
    {
        $suffix = ConfigurationEnum::companySuffix($this->kanvasApp, $this->company);

        $this->company->set(ConfigurationEnum::ACCESS_TOKEN->value . $suffix, 'stored-token');
        $this->company->set(ConfigurationEnum::REFRESH_TOKEN->value . $suffix, 'stored-refresh');
        $this->company->set(ConfigurationEnum::TOKEN_EXPIRES_AT->value . $suffix, Carbon::now()->addHour()->toIso8601String());
    }

    private function disconnectMailbox(): void
    {
        $suffix = ConfigurationEnum::companySuffix($this->kanvasApp, $this->company);

        $this->company->del(ConfigurationEnum::ACCESS_TOKEN->value . $suffix);
        $this->company->del(ConfigurationEnum::REFRESH_TOKEN->value . $suffix);
    }
}
