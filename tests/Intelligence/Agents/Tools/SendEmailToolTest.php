<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendEmailTool as SystemSendEmailTool;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class SendEmailToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social', 'intelligence'];

    /**
     * The Lead factory seeds the people with a faker email, so every test starts from a lead with no
     * email on file and adds back exactly the contacts it wants to exercise.
     */
    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->people->contacts()
            ->whereIn('contacts_types_id', [
                ContactTypeEnum::PRIMARY_EMAIL->value,
                ContactTypeEnum::EMAIL->value,
                ContactTypeEnum::SECONDARY_EMAIL->value,
            ])
            ->delete();

        return $lead;
    }

    private function tool(): SendEmailTool
    {
        $user = auth()->user();

        return new SendEmailTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    private function systemTool(?Agent $agent = null): SystemSendEmailTool
    {
        return new SystemSendEmailTool($agent ?? $this->makeAgent());
    }

    private function makeAgent(): Agent
    {
        $user = auth()->user();

        return Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['name' => 'Email agent ' . Str::random(8), 'user_id' => $user->getId()]);
    }

    public function testSendEmailSendsToTheAddressOnFileAndLogsTheLead(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the **quote** you asked for.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('prospect@example.com', $result['to']);
        $this->assertSame('Your quote', $result['subject']);

        $channel = Channel::getById($result['channel_id']);
        $outbound = Message::getById($result['message_id'], app(Apps::class));

        $this->assertSame(
            SessionChannelService::createChannelSlug('email', 'prospect@example.com'),
            $channel->slug,
        );
        $this->assertSame('mailgun-email', $outbound->messageType->verb);
        $this->assertSame('Here is the **quote** you asked for.', $outbound->message['content']);
        $this->assertSame('Your quote', $outbound->get('title'));
        $this->assertTrue($channel->messages()->whereKey($outbound->getId())->exists());

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'prospect@example.com'
        );

        $note = Message::query()
            ->whereHas(
                'appModuleMessage',
                fn (Builder $query) => $query->where('entity_id', $lead->getId())
                    ->where('system_modules', Lead::class)
            )
            ->get()
            ->first(fn (Message $message): bool => str_contains((string) ($message->message['content'] ?? ''), 'Emailed the prospect'));

        $this->assertNotNull($note);
        $this->assertStringContainsString('Your quote', (string) ($note->message['content'] ?? ''));
        $this->assertSame(0, $note->is_public, 'The audit note stays out of the message feed');
        $this->assertSame(1, $outbound->is_public, 'The email itself stays visible in the feed');
    }

    public function testSendEmailErrorsWhenLeadHasNoEmail(): void
    {
        Notification::fake();
        $lead = $this->makeLead();

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testSendEmailErrorsWhenTheOnlyEmailOptedOut(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('optout@example.com');
        $lead->people->contacts()->where('value', 'optout@example.com')->update(['is_opt_out' => 1]);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testSendEmailAnchorsTheThreadSubjectOnFirstTouch(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('Your quote', Lead::getById($lead->getId())->get('title_email_follow_up'));
    }

    public function testSendEmailNeverOverwritesAnExistingThreadAnchor(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');
        $lead->set('title_email_follow_up', 'Your inquiry about the Civic');

        $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame(
            'Your inquiry about the Civic',
            Lead::getById($lead->getId())->get('title_email_follow_up')
        );
    }

    public function testSendEmailSkipsAHardBouncedAddressAndPrefersADeliverableOne(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('dead@example.com')->markBounce(permanent: true);
        Contact::create([
            'peoples_id' => $lead->people->getId(),
            'contacts_types_id' => ContactTypeEnum::SECONDARY_EMAIL->value,
            'value' => 'reachable@example.com',
            'is_opt_out' => 0,
            'weight' => 0,
        ]);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('reachable@example.com', $result['to']);
    }

    public function testSendEmailErrorsWhenTheOnlyEmailHardBounced(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('dead@example.com')->markBounce(permanent: true);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('bounced', $result['message']);
        Notification::assertNothingSent();
    }

    public function testSendEmailStillSendsToASoftBouncedAddress(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('flaky@example.com')->markBounce(permanent: false);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('flaky@example.com', $result['to']);
    }

    public function testSendEmailRefusesWhenLeadIsDoNotContact(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');
        $lead->set('do_not_contact', 1);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testSendEmailRejectsEmptySubjectOrBody(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: '   ',
            body: 'Here is the quote.',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testSendEmailErrorsOnHallucinatedLead(): void
    {
        Notification::fake();

        $result = $this->tool()->__invoke(
            lead_id: 999999999,
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testSendEmailCopiesAnOtherContactOnTheLeadPerson(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');
        $lead->people->addEmail('spouse@example.com');

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
            cc: 'spouse@example.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('prospect@example.com', $result['to']);
        $this->assertSame(['spouse@example.com'], $result['cc']);
        $this->assertSame([], $result['cc_rejected']);

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getCc() === ['spouse@example.com']
        );
    }

    public function testSendEmailCopiesAContactOnTheLeadOrganization(): void
    {
        Notification::fake();
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $org = Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Latino Express',
            'address' => '',
            'total_employees' => 0,
        ]);
        $hrPerson = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['firstname' => 'Karla', 'lastname' => 'Feliz']);
        $hrPerson->contacts()->delete();
        $hrPerson->addEmail('hr@example.com');
        $hrPerson->organizations()->attach($org->getId(), ['created_at' => now()]);

        $lead->organization_id = $org->getId();
        $lead->saveOrFail();

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Policy update',
            body: 'Here is the update.',
            cc: 'hr@example.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(['hr@example.com'], $result['cc']);
        $this->assertSame([], $result['cc_rejected']);
    }

    public function testSendEmailCopiesAParticipantOnTheLead(): void
    {
        Notification::fake();
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $coBuyer = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['firstname' => 'Ana', 'lastname' => 'Perez']);
        $coBuyer->contacts()->delete();
        $coBuyer->addEmail('cobuyer@example.com');

        $lead->addParticipant($coBuyer);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
            cc: 'cobuyer@example.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('prospect@example.com', $result['to']);
        $this->assertSame(['cobuyer@example.com'], $result['cc']);
        $this->assertSame([], $result['cc_rejected']);

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getCc() === ['cobuyer@example.com']
        );
    }

    public function testSendEmailDoesNotCopyARemovedParticipant(): void
    {
        Notification::fake();
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $formerParticipant = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['firstname' => 'Luis', 'lastname' => 'Gomez']);
        $formerParticipant->contacts()->delete();
        $formerParticipant->addEmail('former@example.com');

        $lead->addParticipant($formerParticipant);
        $lead->removeParticipant($formerParticipant);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
            cc: 'former@example.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['cc']);
        $this->assertSame(['former@example.com'], $result['cc_rejected']);
    }

    public function testSendEmailDoesNotCopyAParticipantBelongingToAnotherCompany(): void
    {
        Notification::fake();
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        // A participant row can be written by paths that never check the tenant (connector imports,
        // lead merges), so the CC allowlist has to reject a foreign person on its own.
        $foreignPerson = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->create(['firstname' => 'Otra', 'lastname' => 'Empresa']);
        $foreignPerson->contacts()->delete();
        $foreignPerson->addEmail('outsider@example.com');

        $lead->addParticipant($foreignPerson);

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
            cc: 'outsider@example.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['cc']);
        $this->assertSame(['outsider@example.com'], $result['cc_rejected']);
    }

    public function testSendEmailAllowsNoCcWhenTheToolHasNoTenantContext(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');
        $lead->people->addEmail('spouse@example.com');

        $result = new SendEmailTool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
            cc: 'spouse@example.com',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }

    public function testSendEmailDropsCcAddressesNotOnFile(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
            cc: 'attacker@evil.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['cc']);
        $this->assertSame(['attacker@evil.com'], $result['cc_rejected']);

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getCc() === []
        );
    }

    public function testSendEmailIgnoresACcThatDuplicatesThePrimaryRecipient(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            subject: 'Your quote',
            body: 'Here is the quote.',
            cc: 'PROSPECT@example.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['cc']);
        $this->assertSame([], $result['cc_rejected']);
    }

    public function testEmailsExternalRecipientViaSmtpFallback(): void
    {
        Mail::fake();
        $agent = $this->makeAgent();

        $result = $this->systemTool($agent)->__invoke(
            to: ' client@example.com ',
            subject: '  Payment reminder  ',
            body: '  Please review **invoice 42**.  ',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('client@example.com', $result['to']);
        $this->assertSame('Payment reminder', $result['subject']);
        $this->assertSame([], $result['cc']);
        $this->assertNotEmpty($result['from']);
        Mail::assertSent(
            KanvasMailable::class,
            fn (KanvasMailable $mail): bool => $mail->hasTo('client@example.com')
                && $mail->subject === 'Payment reminder',
        );
    }

    public function testEmailsExternalRecipientWithCc(): void
    {
        Mail::fake();

        $result = $this->systemTool()->__invoke(
            to: 'client@example.com',
            subject: 'Account update',
            body: 'The account is current.',
            cc: ' copy@example.com, invalid, copy@example.com, , another@example.com ',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(['copy@example.com', 'another@example.com'], $result['cc']);
        $this->assertSame(
            'Email sent to client@example.com (cc: copy@example.com, another@example.com).',
            $result['message'],
        );
        Mail::assertSent(
            KanvasMailable::class,
            fn (KanvasMailable $mail): bool => $mail->hasTo('client@example.com')
                && $mail->hasCc('copy@example.com')
                && $mail->hasCc('another@example.com'),
        );
    }

    public function testEmailsExternalRecipientViaAgentMailbox(): void
    {
        Mail::fake();
        config([
            'services.mailgun.secret' => 'key-test',
            'services.mailgun.domain' => 'agents.kanvas.test',
        ]);
        Http::fake([
            'api.mailgun.net/v3/*/messages' => Http::response(['id' => '<sent@agents.kanvas.test>']),
        ]);
        $agent = $this->makeAgent();
        $address = Str::slug($agent->name) . '@agents.kanvas.test';
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $address);

        $result = $this->systemTool($agent)->__invoke(
            to: 'client@example.com',
            subject: 'Mailbox test',
            body: 'Hello from the agent.',
            cc: 'copy@example.com',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($address, $result['from']);
        Http::assertSent(
            fn ($request): bool => $request->method() === 'POST'
                && str_contains($request->url(), '/messages')
                && str_contains((string) $request->body(), 'client@example.com')
                && str_contains((string) $request->body(), 'copy@example.com'),
        );
        Mail::assertNothingSent();
    }

    public function testRejectsInvalidEmailAddress(): void
    {
        Mail::fake();

        $result = $this->systemTool()->__invoke(
            to: 'not-an-email',
            subject: 'Hello',
            body: 'Hello there.',
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame("The recipient email address 'not-an-email' is invalid.", $result['message']);
        Mail::assertNothingSent();
    }

    public function testRejectsEmptySubjectOrBody(): void
    {
        Mail::fake();
        $tool = $this->systemTool();

        $emptySubject = $tool->__invoke(
            to: 'client@example.com',
            subject: '  ',
            body: 'A body.',
        );
        $emptyBody = $tool->__invoke(
            to: 'client@example.com',
            subject: 'A subject.',
            body: '  ',
        );
        $emptyRecipient = $tool->__invoke(
            to: '  ',
            subject: 'A subject.',
            body: 'A body.',
        );

        $this->assertSame('error', $emptySubject['status']);
        $this->assertSame('error', $emptyBody['status']);
        $this->assertSame('error', $emptyRecipient['status']);
        Mail::assertNothingSent();
    }

    public function testErrorsWithoutAgentInScope(): void
    {
        Mail::fake();

        $result = new SystemSendEmailTool()->__invoke(
            to: 'client@example.com',
            subject: 'Hello',
            body: 'Hello there.',
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('No agent is in scope, so I cannot send an email.', $result['message']);
        Mail::assertNothingSent();
    }

    public function testSentEmailIsRecordedInAgentLedger(): void
    {
        Mail::fake();
        $agent = $this->makeAgent();

        $result = $this->systemTool($agent)->__invoke(
            to: 'client@example.com',
            subject: 'Invoice reminder',
            body: 'Invoice 42 is due.',
            cc: 'copy@example.com',
        );

        $event = Event::query()
            ->where('apps_id', app(Apps::class)->getId())
            ->where('companies_id', auth()->user()->getCurrentCompany()->getId())
            ->where('event_type', SystemSendEmailTool::LEDGER_EVENT)
            ->where('actor_type', 'Agent')
            ->where('actor_id', $agent->getId())
            ->latest('id')
            ->first();

        $this->assertSame('success', $result['status']);
        $this->assertNotNull($event);
        $this->assertSame('Intelligence', $event->source_domain);
        $this->assertSame('client@example.com', $event->payload['to']);
        $this->assertSame(['copy@example.com'], $event->payload['cc']);
        $this->assertSame('Invoice reminder', $event->payload['subject']);
        $this->assertSame('Invoice 42 is due.', $event->payload['body']);
    }
}
