<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class SendEmailToolTest extends TestCase
{
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

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'prospect@example.com'
        );

        $this->assertTrue(
            Message::query()
                ->whereHas(
                    'appModuleMessage',
                    fn (Builder $query) => $query->where('entity_id', $lead->getId())
                        ->where('system_modules', Lead::class)
                )
                ->get()
                ->contains(fn (Message $message): bool => str_contains((string) ($message->message['content'] ?? ''), 'Your quote'))
        );
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
}
