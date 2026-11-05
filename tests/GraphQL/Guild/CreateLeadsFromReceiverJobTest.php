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

    public function testTemplateReceiverMapsFlatFormPostIntoPeopleAndCustomFields(): void
    {
        $this->leadReceiver->update([
            'template' => [
                'Member' => ['name' => 'member', 'type' => 'customField'],
                'First_Name' => ['name' => 'firstname', 'type' => 'string'],
                'Last_Name' => ['name' => 'lastname', 'type' => 'string'],
                'Phone' => ['name' => 'phone', 'type' => 'string'],
                'Email' => ['name' => 'email', 'type' => 'string'],
                'zip' => ['name' => 'zip', 'type' => 'customField'],
                'Company' => ['name' => 'Company', 'type' => 'customField'],
                'Lead_Source' => ['name' => 'Lead_Source', 'type' => 'customField'],
                'SMS_Opt_Out' => ['name' => 'sms_opt_out', 'type' => 'customField'],
                'Credit_Score' => ['name' => 'Credit_Score', 'type' => 'customField'],
            ],
        ]);

        $email = 'flat-post-' . Str::random(8) . '@example.com';
        $payload = [
            'consentAuthorizedAt' => '2026-11-04T01:54:45.936Z',
            'Email' => $email,
            'email' => $email,
            'Phone' => '(390) 239-4230',
            'phone' => '(390) 239-4230',
            'clean_phone' => '3902394230',
            'First_Name' => 'Flat',
            'first_name' => 'Flat',
            'Last_Name' => 'Post',
            'last_name' => 'Post',
            'Company' => 'Acme Lending',
            'Company_Name' => 'Acme Lending',
            'Lead_Source' => 'Apply Landing Page',
            'Credit_Pull_Approved' => 'false',
            'Do_you_have_a_Co_Applicant_1' => 'No',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $lead = Lead::find($result['lead_id']);
        $this->assertNotNull($lead);
        $this->assertSame('Flat Post', trim($lead->title));
        $this->assertSame('Flat', $lead->people->firstname);
        $this->assertSame('Post', $lead->people->lastname);
        $this->assertSame($email, $lead->email);
        $this->assertSame('3902394230', $lead->phone);

        $contactsByType = $lead->people->contacts
            ->groupBy('contacts_types_id')
            ->map(fn ($group) => $group->pluck('value')->all());

        $this->assertContains($email, $contactsByType[ContactTypeEnum::EMAIL->value] ?? []);
        $this->assertContains('3902394230', $contactsByType[ContactTypeEnum::PHONE->value] ?? []);

        $this->assertSame('Acme Lending', $lead->get('Company'));
        $this->assertSame('Apply Landing Page', $lead->get('Lead_Source'));
        $this->assertSame('2026-11-04T01:54:45.936Z', $lead->get('consentAuthorizedAt'));
        $this->assertSame('No', $lead->get('Do_you_have_a_Co_Applicant_1'));
        $this->assertNull($lead->get('people'));
        $this->assertEmpty($lead->organization_id);
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
