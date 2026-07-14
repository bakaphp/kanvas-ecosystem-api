<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
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

    public function testSendEmailSendsToTheAddressOnFileAndLogsTheLead(): void
    {
        Notification::fake();
        $lead = $this->makeLead();
        $lead->people->addEmail('prospect@example.com');

        $result = new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
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

        new SendEmailTool()->__invoke(
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

        new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
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

        $result = new SendEmailTool()->__invoke(
            lead_id: 999999999,
            subject: 'Your quote',
            body: 'Here is the quote.',
        );

        $this->assertSame('error', $result['status']);
        Notification::assertNothingSent();
    }
}
