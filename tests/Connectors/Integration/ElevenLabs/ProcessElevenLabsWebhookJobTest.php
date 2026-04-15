<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ElevenLabs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ElevenLabs\Webhooks\ProcessElevenLabsAgentWebhookJob;
use Kanvas\Connectors\ElevenLabs\Webhooks\ProcessElevenLabsCalendarEventWebhookJob;
use Kanvas\Connectors\ElevenLabs\Webhooks\ProcessElevenLabsHandOffWebhookJob;
use Kanvas\Connectors\ElevenLabs\Webhooks\ProcessElevenLabsTranscriptWebhookJob;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class ProcessElevenLabsWebhookJobTest extends TestCase
{
    private string $testPhone = '+18095551234';
    private ?Lead $testLead = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Warm',
            ],
            [
                'description' => 'Warm Lead Type',
                'is_active' => true,
                'uuid' => Str::uuid(),
            ]
        );
    }

    public function testAgentWebhookFindsLeadByPhone(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsAgentWebhookJob::class,
            [
                'source' => 'elevenlabs_agent',
                'phone' => $this->testPhone,
            ]
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lead', $result);
        $this->assertArrayHasKey('voice_context', $result);
        $this->assertArrayHasKey('session', $result);
        $this->assertEquals($this->testLead->getId(), $result['lead']['id']);
    }

    public function testAgentWebhookReturnsErrorWithoutPhone(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsAgentWebhookJob::class,
            ['source' => 'elevenlabs_agent']
        );

        $this->assertIsArray($result);
        $this->assertEquals(422, $result['status']);
        $this->assertEquals('Phone number is required', $result['message']);
    }

    public function testAgentWebhookCreatesLeadWhenNotFound(): void
    {
        $newPhone = '+1809' . rand(1000000, 9999999);

        $result = $this->dispatchJob(
            ProcessElevenLabsAgentWebhookJob::class,
            [
                'source' => 'elevenlabs_agent',
                'phone' => $newPhone,
            ]
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lead', $result);
        $this->assertNotEmpty($result['lead']['id']);
    }

    public function testTranscriptWebhookSavesTranscription(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'type' => 'post_call_transcription',
                'event_timestamp' => time(),
                'data' => [
                    'agent_id' => 'agent_123',
                    'conversation_id' => 'conv_' . Str::random(10),
                    'status' => 'done',
                    'transcript' => [
                        [
                            'role' => 'agent',
                            'message' => 'Hello, how can I help you?',
                            'time_in_call_secs' => 0,
                        ],
                        [
                            'role' => 'user',
                            'message' => 'I want to schedule a test drive.',
                            'time_in_call_secs' => 3,
                        ],
                    ],
                    'metadata' => [
                        'start_time_unix_secs' => time(),
                        'call_duration_secs' => 120,
                        'termination_reason' => 'agent_ended',
                        'phone_call' => [
                            'direction' => 'inbound',
                            'from_number' => $this->testPhone,
                            'to_number' => '+18001234567',
                        ],
                    ],
                    'analysis' => [
                        'call_successful' => 'success',
                        'transcript_summary' => 'Customer called to schedule a test drive.',
                    ],
                ],
            ]
        );

        $this->assertIsArray($result);
        $this->assertEquals('Transcript saved', $result['message']);
        $this->assertArrayHasKey('message_id', $result);
        $this->assertEquals($this->testLead->getId(), $result['lead_id']);
    }

    public function testTranscriptWebhookHandlesCallFailure(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'type' => 'call_initiation_failure',
                'event_timestamp' => time(),
                'data' => [
                    'agent_id' => 'agent_123',
                    'conversation_id' => 'conv_fail_' . Str::random(10),
                    'failure_reason' => 'busy',
                    'metadata' => [
                        'type' => 'twilio',
                        'body' => [
                            'from_number' => '+18001234567',
                            'to_number' => $this->testPhone,
                            'error_reason' => 'User busy',
                        ],
                    ],
                ],
            ]
        );

        $this->assertIsArray($result);
        $this->assertEquals('Call failure recorded', $result['message']);
        $this->assertEquals('busy', $result['failure_reason']);
    }

    public function testTranscriptWebhookHandlesUnknownType(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'type' => 'unknown_type',
                'data' => [],
            ]
        );

        $this->assertIsArray($result);
        $this->assertStringContainsString('Unknown webhook type', $result['message']);
    }

    public function testCalendarEventWebhookCreatesEvent(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsCalendarEventWebhookJob::class,
            [
                'phone' => $this->testPhone,
                'date' => '2026-05-01',
                'start_time' => '14:00',
                'end_time' => '15:00',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'event_name' => 'Test Drive Appointment',
                'conversation_summary' => 'Customer wants to test drive a Honda Civic.',
            ]
        );

        $this->assertIsArray($result);
        $this->assertStringContainsString('Calendar event created', $result['message']);
        $this->assertArrayHasKey('event_id', $result);
        $this->assertEquals($this->testLead->getId(), $result['lead_id']);
        $this->assertEquals('2026-05-01', $result['date']);
        $this->assertEquals('14:00', $result['start_time']);
    }

    public function testCalendarEventWebhookUpdatesPeopleInfo(): void
    {
        $this->createTestLeadWithPhone();

        $this->dispatchJob(
            ProcessElevenLabsCalendarEventWebhookJob::class,
            [
                'phone' => $this->testPhone,
                'date' => '2026-05-01',
                'firstname' => 'UpdatedFirst',
                'lastname' => 'UpdatedLast',
            ]
        );

        $this->testLead->refresh();
        $people = $this->testLead->people;
        $this->assertEquals('UpdatedFirst', $people->firstname);
        $this->assertEquals('UpdatedLast', $people->lastname);
    }

    public function testCalendarEventWebhookRequiresPhone(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsCalendarEventWebhookJob::class,
            ['date' => '2026-05-01']
        );

        $this->assertEquals(422, $result['status']);
        $this->assertEquals('Phone number is required', $result['message']);
    }

    public function testCalendarEventWebhookRequiresDate(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsCalendarEventWebhookJob::class,
            ['phone' => $this->testPhone]
        );

        $this->assertEquals(422, $result['status']);
        $this->assertEquals('Date is required', $result['message']);
    }

    public function testCalendarEventWebhookReturns404WhenNoLead(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsCalendarEventWebhookJob::class,
            [
                'phone' => '+19999999999',
                'date' => '2026-05-01',
            ]
        );

        $this->assertEquals(404, $result['status']);
    }

    public function testHandOffWebhookTriggersHandoff(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsHandOffWebhookJob::class,
            [
                'phone' => $this->testPhone,
                'handoff_type' => 'human',
                'conversation_summary' => 'Customer wants to speak to a manager.',
                'firstname' => 'Jane',
                'lastname' => 'Smith',
            ]
        );

        $this->assertIsArray($result);
        $this->assertEquals('Handoff triggered', $result['message']);
        $this->assertEquals($this->testLead->getId(), $result['lead_id']);
        $this->assertEquals('human', $result['handoff_type']);
    }

    public function testHandOffWebhookDefaultsToHuman(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsHandOffWebhookJob::class,
            ['phone' => $this->testPhone]
        );

        $this->assertIsArray($result);
        $this->assertEquals('human', $result['handoff_type']);
    }

    public function testHandOffWebhookRequiresPhone(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsHandOffWebhookJob::class,
            ['handoff_type' => 'human']
        );

        $this->assertEquals(422, $result['status']);
    }

    public function testHandOffWebhookReturns404WhenNoLead(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsHandOffWebhookJob::class,
            ['phone' => '+19999999999']
        );

        $this->assertEquals(404, $result['status']);
    }

    public function testHandOffWebhookUpdatesPeopleInfo(): void
    {
        $this->createTestLeadWithPhone();

        $this->dispatchJob(
            ProcessElevenLabsHandOffWebhookJob::class,
            [
                'phone' => $this->testPhone,
                'firstname' => 'NewFirst',
                'lastname' => 'NewLast',
            ]
        );

        $this->testLead->refresh();
        $people = $this->testLead->people;
        $this->assertEquals('NewFirst', $people->firstname);
        $this->assertEquals('NewLast', $people->lastname);
    }

    private function createTestLeadWithPhone(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $branch = $company->defaultBranch;

        $contactData = [
            [
                'value' => $this->testPhone,
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ],
        ];

        $peopleDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'Test',
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Person',
        );

        $people = new CreatePeopleAction($peopleDto)->execute();

        $leadType = LeadType::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'Warm')
            ->firstOrFail();

        $leadData = new LeadData(
            app: $app,
            branch: $branch,
            user: $user,
            title: 'Test ElevenLabs Lead',
            pipeline_stage_id: 0,
            people: new PeopleDto(
                $app,
                $branch,
                $user,
                (string) $people->firstname,
                Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                Address::collect([], DataCollection::class),
                (string) $people->lastname,
                $people->id,
            ),
            leads_owner_id: $user->getId(),
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: 0,
        );

        $this->testLead = new CreateLeadAction($leadData)->execute();
    }

    private function createReceiver(string $jobClass): ReceiverWebhook
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => $jobClass],
            ['name' => class_basename($jobClass)]
        );

        return ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    private function dispatchJob(string $jobClass, array $payload): array
    {
        $receiver = $this->createReceiver($jobClass);

        $request = Request::create(
            'https://localhost/v1/receiver/' . $receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($receiver, $request)->execute();

        Queue::fake();

        $job = new $jobClass($webhookRequest);

        return $job->handle();
    }
}
