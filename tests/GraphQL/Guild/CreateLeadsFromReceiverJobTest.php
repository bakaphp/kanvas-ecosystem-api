<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\Models\LeadRotationAgent;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class CreateLeadsFromReceiverJobTest extends TestCase
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
            ['model_name' => CreateLeadsFromReceiverJob::class],
            ['name' => 'CreateLeadsFromReceiverJob'],
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
                ],
            ]);
    }

    public function testCreateLeadFromWebhookPayload(): void
    {
        $title = fake()->title();
        $payload = [
            'title' => $title,
            'people' => [
                'firstname' => 'John',
                'lastname' => 'Doe',
                'contacts' => [
                    ['value' => 'jdoe@example.com', 'weight' => 0, 'contacts_types_id' => 1],
                    ['value' => '8292001222', 'weight' => 0, 'contacts_types_id' => 2],
                ],
            ],
            'pipeline_stage_id' => 0,
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lead_id', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('receiver', $result);
        $this->assertStringContainsString('Lead created successfully', $result['message']);
        $this->assertEquals($this->leadReceiver->getId(), $result['receiver']);

        $lead = Lead::find($result['lead_id']);
        $this->assertNotNull($lead);
        $this->assertEquals($title, $lead->title);
        $this->assertEquals($this->leadReceiver->getId(), $lead->leads_receivers_id);
    }

    public function testSavePhoneAsCellphoneFlagDuplicatesPhoneContact(): void
    {
        $this->receiver->update([
            'configuration' => array_merge(
                $this->receiver->configuration,
                ['save_phone_as_cellphone' => true],
            ),
        ]);

        $phone = '8292001222';
        $payload = [
            'title' => fake()->title(),
            'people' => [
                'firstname' => 'John',
                'lastname' => 'Doe',
                'contacts' => [
                    ['value' => 'jdoe@example.com', 'weight' => 0, 'contacts_types_id' => 1],
                    ['value' => $phone, 'weight' => 0, 'contacts_types_id' => 2],
                ],
            ],
            'pipeline_stage_id' => 0,
        ];

        $result = $this->dispatchWebhookJob($payload);

        $lead = Lead::find($result['lead_id']);
        $this->assertNotNull($lead);

        $contactsByType = $lead->people->contacts
            ->groupBy('contacts_types_id')
            ->map(fn ($group) => $group->pluck('value')->all());

        $this->assertContains($phone, $contactsByType[ContactTypeEnum::PHONE->value] ?? []);
        $this->assertContains($phone, $contactsByType[ContactTypeEnum::CELLPHONE->value] ?? []);
    }

    public function testCreateLeadWithRotationAssignsAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $rotation = LeadRotation::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Rotation ' . fake()->word(),
            'hits' => 0,
            'leads_rotations_email' => '',
            'config' => [
                'email_template' => 'new-lead',
                'notification_mode' => 'NOTIFY_AGENTS',
                'notification_user_mode' => 'NOTIFY_OWNER',
            ],
        ]);

        LeadRotationAgent::create([
            'leads_rotations_id' => $rotation->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'percent' => 100,
        ]);

        $this->leadReceiver->update(['rotations_id' => $rotation->getId()]);

        Notification::fake();

        $payload = [
            'title' => fake()->title(),
            'people' => [
                'firstname' => 'Jane',
                'lastname' => 'Smith',
                'contacts' => [
                    ['value' => 'jane@example.com', 'weight' => 0, 'contacts_types_id' => 1],
                ],
            ],
            'pipeline_stage_id' => 0,
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertArrayHasKey('lead_id', $result);

        $lead = Lead::find($result['lead_id']);
        $this->assertEquals($user->getId(), $lead->leads_owner_id);
        $this->assertEquals($user->getId(), $result['lead']['leads_owner_id']);
    }

    public function testHandlesCustomFieldsWithMissingDataKey(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $rotation = LeadRotation::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Rotation ' . fake()->word(),
            'hits' => 0,
            'leads_rotations_email' => '',
            'config' => ['email_template' => 'new-lead'],
        ]);

        LeadRotationAgent::create([
            'leads_rotations_id' => $rotation->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'percent' => 100,
        ]);

        $this->leadReceiver->update(['rotations_id' => $rotation->getId()]);

        Notification::fake();

        $payload = [
            'title' => fake()->title(),
            'people' => [
                'firstname' => 'Otto',
                'lastname' => 'Tester',
                'contacts' => [
                    ['value' => 'otto@example.com', 'weight' => 0, 'contacts_types_id' => 1],
                ],
            ],
            'custom_fields' => [
                ['name' => 'good_field', 'data' => 'value'],
                ['name' => 'malformed_field'], // missing 'data' — used to crash mapCustomFields
            ],
            'pipeline_stage_id' => 0,
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertArrayHasKey('lead_id', $result);
        $this->assertNotNull(Lead::find($result['lead_id']));
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

        $job = new CreateLeadsFromReceiverJob($webhookRequest);
        $result = $job->handle();

        if ($result === null) {
            $webhookRequest->refresh();
            $exception = $webhookRequest->exception ?? ['message' => 'unknown'];
            $this->fail('Job handle() returned null. Webhook exception: ' . json_encode($exception));
        }

        return $result;
    }
}
