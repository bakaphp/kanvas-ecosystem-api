<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\EmailTemplateEnum;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverWithConfirmationJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Notifications\LeadReceivedConfirmationNotification;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Templates\Models\Templates;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class CreateLeadsFromReceiverWithConfirmationJobTest extends TestCase
{
    private ReceiverWebhook $receiver;
    private LeadReceiver $leadReceiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Test Type ' . fake()->word(),
            'description' => 'Test',
            'is_active' => true,
            'uuid' => Str::uuid(),
        ]);

        $leadSource = LeadSource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Test Source ' . fake()->word(),
            'description' => 'Test',
            'is_active' => true,
            'uuid' => Str::uuid(),
            'leads_types_id' => $leadType->getId(),
        ]);

        $this->leadReceiver = LeadReceiver::create([
            'name' => 'Test Receiver ' . fake()->word(),
            'users_id' => $user->getId(),
            'agents_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'leads_sources_id' => $leadSource->getId(),
            'lead_types_id' => $leadType->getId(),
            'source_name' => 'test-source',
            'is_default' => false,
        ]);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => CreateLeadsFromReceiverWithConfirmationJob::class],
            ['name' => 'CreateLeadsFromReceiverWithConfirmationJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    'receiver_id' => $this->leadReceiver->getId(),
                    'flag' => 'user',
                    'show_custom_fields' => false,
                    'send_confirmation' => true,
                ],
            ]);
    }

    public function testSendsConfirmationToSubmitterWithUuidReference(): void
    {
        Notification::fake();

        $email = 'submitter+' . fake()->unique()->uuid() . '@example.com';
        $result = $this->dispatchWebhookJob($this->payloadWithEmail($email));

        $lead = Lead::find($result['lead_id']);
        $this->assertNotNull($lead);

        $expectedReference = strtoupper(substr($lead->uuid, -6));

        Notification::assertSentOnDemand(
            LeadReceivedConfirmationNotification::class,
            function (LeadReceivedConfirmationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, $expectedReference) {
                return $notifiable->routes['mail'] === $email
                    && $notification->reference === $expectedReference
                    && strlen($notification->reference) === 6;
            },
        );
    }

    public function testSendsConfirmationOverEmailAndSms(): void
    {
        $this->receiver->update([
            'configuration' => array_merge(
                $this->receiver->configuration,
                ['confirmation_channels' => ['mail', 'sms']],
            ),
        ]);

        Notification::fake();

        $email = 'submitter+' . fake()->unique()->uuid() . '@example.com';
        $phone = '8292001222';
        $result = $this->dispatchWebhookJob($this->payloadWithEmail($email, $phone));

        $lead = Lead::find($result['lead_id']);
        $expectedReference = strtoupper(substr($lead->uuid, -6));
        $expectedEmail = $lead->people->getEmails()->first()->value;
        $expectedPhone = $lead->people->getAllPhones()->first()->value;

        Notification::assertSentOnDemand(
            LeadReceivedConfirmationNotification::class,
            function (LeadReceivedConfirmationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($expectedEmail, $expectedPhone, $expectedReference) {
                return $notifiable->routes['mail'] === $expectedEmail
                    && $notifiable->routes['sms'] === $expectedPhone
                    && in_array('mail', $notification->channels, true)
                    && in_array('sms', $notification->channels, true)
                    && $notification->reference === $expectedReference;
            },
        );
    }

    public function testDoesNotSendConfirmationWhenFlagDisabled(): void
    {
        $this->receiver->update([
            'configuration' => array_merge(
                $this->receiver->configuration,
                ['send_confirmation' => false],
            ),
        ]);

        Notification::fake();

        $result = $this->dispatchWebhookJob($this->payloadWithEmail('no-confirm@example.com'));

        $this->assertArrayHasKey('lead_id', $result);
        Notification::assertNothingSent();
    }

    public function testRendersDedicatedTemplatesPerChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        Templates::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => EmailTemplateEnum::LEAD_RECEIVED_CONFIRMATION->value,
            'subject' => 'Lead received',
            'template' => '<p>Thanks! Your reference is <strong>{{ $reference }}</strong></p>',
        ]);

        Templates::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => EmailTemplateEnum::LEAD_RECEIVED_CONFIRMATION_SMS->value,
            'template' => 'Your reference is {{ $reference }}',
        ]);

        Notification::fake();
        $result = $this->dispatchWebhookJob($this->payloadWithEmail('render@example.com'));
        $lead = Lead::find($result['lead_id']);

        $reference = 'ZX98QW';
        $notification = new LeadReceivedConfirmationNotification(
            $lead,
            [
                'app' => $lead->app,
                'company' => $lead->company,
                'lead' => $lead,
                'reference' => $reference,
            ],
        );

        $emailBody = $notification->getEmailContent();
        $this->assertStringContainsString($reference, $emailBody);
        $this->assertStringContainsString('<strong>', $emailBody);

        $smsNotifiable = new AnonymousNotifiable();
        $smsNotifiable->route('sms', '8290000000');
        $smsBody = $notification->toSms($smsNotifiable)['content'];

        $this->assertStringContainsString($reference, $smsBody);
        // the SMS must render the dedicated plain-text template, never the HTML email body
        $this->assertStringNotContainsString('<strong>', $smsBody);
        $this->assertStringNotContainsString('<p>', $smsBody);
    }

    private function payloadWithEmail(string $email, string $phone = '8292001222'): array
    {
        return [
            'title' => fake()->title(),
            'people' => [
                'firstname' => 'John',
                'lastname' => 'Doe',
                'contacts' => [
                    ['value' => $email, 'weight' => 0, 'contacts_types_id' => 1],
                    ['value' => $phone, 'weight' => 0, 'contacts_types_id' => 2],
                ],
            ],
            'pipeline_stage_id' => 0,
        ];
    }

    private function dispatchWebhookJob(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        Queue::fake();

        $job = new CreateLeadsFromReceiverWithConfirmationJob($webhookRequest);
        $result = $job->handle();

        if ($result === null) {
            $webhookRequest->refresh();
            $exception = $webhookRequest->exception ?? ['message' => 'unknown'];
            $this->fail('Job handle() returned null. Webhook exception: ' . json_encode($exception));
        }

        return $result;
    }
}
