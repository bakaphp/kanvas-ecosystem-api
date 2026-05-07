<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ElevenLabs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ElevenLabs\Webhooks\ProcessElevenLabsAgentWebhookJob;
use Kanvas\Connectors\ElevenLabs\Webhooks\ProcessElevenLabsCalendarEventWebhookJob;
use Kanvas\Connectors\ElevenLabs\Webhooks\ProcessElevenLabsConversationInitiationWebhookJob;
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
    private string $testPhone;
    private ?Lead $testLead = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPhone = '+1809' . random_int(1000000, 9999999);

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
        $this->markTestSkipped('Requires voiceOutreachAgent and VoiceBridge configuration');
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
        $this->markTestSkipped('Requires voiceOutreachAgent and VoiceBridge configuration');
    }

    public function testConversationInitiationWebhookReturnsClientDataForLead(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsConversationInitiationWebhookJob::class,
            [
                'caller_id' => $this->testPhone,
                'agent_id' => 'agent_123',
                'called_number' => '+18001234567',
                'call_sid' => 'CA123',
            ]
        );

        $this->assertEquals('conversation_initiation_client_data', $result['type']);
        $this->assertArrayHasKey('dynamic_variables', $result);
        $this->assertTrue($result['dynamic_variables']['contact_exists']);
        $this->assertTrue($result['dynamic_variables']['has_open_opportunity']);
        $this->assertEquals($this->testLead->uuid, $result['dynamic_variables']['lead_uuid']);
        $this->assertEquals('Test Person', $result['dynamic_variables']['customer_name']);
        $this->assertEquals('agent_123', $result['dynamic_variables']['elevenlabs_agent_id']);
        $this->assertEquals('CA123', $result['dynamic_variables']['call_sid']);
    }

    public function testConversationInitiationWebhookReturnsDefaultsWhenCallerIsUnknown(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsConversationInitiationWebhookJob::class,
            [
                'caller_id' => '+1809' . random_int(1000000, 9999999),
                'agent_id' => 'agent_123',
            ]
        );

        $this->assertEquals('conversation_initiation_client_data', $result['type']);
        $this->assertFalse($result['dynamic_variables']['contact_exists']);
        $this->assertFalse($result['dynamic_variables']['has_open_opportunity']);
        $this->assertEquals('', $result['dynamic_variables']['lead_uuid']);
    }

    public function testTranscriptWebhookWithPhoneCallMetadata(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'data' => [
                    'agent_id' => 'agent_123',
                    'agent_name' => 'Sally',
                    'conversation_id' => 'conv_' . Str::random(10),
                    'status' => 'done',
                    'transcript' => [
                        [
                            'role' => 'agent',
                            'message' => 'Hello, how can I help you?',
                            'time_in_call_secs' => 0,
                            'source_medium' => null,
                        ],
                        [
                            'role' => 'user',
                            'message' => 'I want to schedule a test drive.',
                            'time_in_call_secs' => 3,
                            'source_medium' => 'audio',
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
                        'call_summary_title' => 'Test Drive Scheduling',
                    ],
                ],
            ]
        );

        $this->assertIsArray($result);
        $this->assertEquals('Transcript saved', $result['message']);
        $this->assertArrayHasKey('message_id', $result);
        $this->assertEquals($this->testLead->getId(), $result['lead_id']);
    }

    public function testTranscriptWebhookWithDataCollectionPhone(): void
    {
        $this->createTestLeadWithPhone();
        $phoneDigits = preg_replace('/[^0-9]/', '', $this->testPhone);

        $result = $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'data' => [
                    'agent_id' => 'agent_123',
                    'agent_name' => 'Sally',
                    'conversation_id' => 'conv_dc_' . Str::random(10),
                    'status' => 'done',
                    'transcript' => [
                        [
                            'role' => 'agent',
                            'message' => 'Thank you for calling, this is Sally.',
                            'time_in_call_secs' => 0,
                        ],
                        [
                            'role' => 'user',
                            'message' => 'I want a showroom appointment.',
                            'time_in_call_secs' => 3,
                            'source_medium' => 'audio',
                        ],
                    ],
                    'metadata' => [
                        'call_duration_secs' => 76,
                        'termination_reason' => 'end_call tool was called.',
                        'phone_call' => null,
                    ],
                    'analysis' => [
                        'call_successful' => 'failure',
                        'transcript_summary' => 'Customer booked a showroom appointment.',
                        'call_summary_title' => 'Showroom Appointment Booking',
                        'data_collection_results' => [
                            'caller_phone_number' => [
                                'value' => $phoneDigits,
                                'data_collection_id' => 'caller_phone_number',
                            ],
                            'caller_name' => [
                                'value' => 'Geraldie Miguel de la Rosa Hernandez',
                                'data_collection_id' => 'caller_name',
                            ],
                            'call_intent' => [
                                'value' => 'sales',
                                'data_collection_id' => 'call_intent',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertIsArray($result);
        $this->assertEquals('Transcript saved', $result['message']);
        $this->assertEquals($this->testLead->getId(), $result['lead_id']);
    }

    public function testTranscriptWebhookUpdatesPeopleNameFromAnalysis(): void
    {
        $this->createTestLeadWithPhone();

        /** @var \Kanvas\Guild\Customers\Models\People $people */
        $people = $this->testLead->people;
        $people->firstname = $this->testPhone;
        $people->lastname = '';
        $people->saveOrFail();

        $phoneDigits = preg_replace('/[^0-9]/', '', $this->testPhone);

        $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'data' => [
                    'agent_id' => 'agent_123',
                    'conversation_id' => 'conv_name_' . Str::random(10),
                    'status' => 'done',
                    'transcript' => [],
                    'metadata' => [
                        'call_duration_secs' => 30,
                        'termination_reason' => 'end_call',
                        'phone_call' => null,
                    ],
                    'analysis' => [
                        'call_successful' => 'success',
                        'transcript_summary' => 'Quick call.',
                        'data_collection_results' => [
                            'caller_phone_number' => [
                                'value' => $phoneDigits,
                            ],
                            'caller_name' => [
                                'value' => 'Maria Santos',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->testLead->refresh();
        $people = $this->testLead->people;
        $this->assertEquals('Maria', $people->firstname);
        $this->assertEquals('Santos', $people->lastname);
    }

    public function testTranscriptWebhookHandlesCallFailure(): void
    {
        $this->createTestLeadWithPhone();

        $result = $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'type' => 'call_initiation_failure',
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

    public function testTranscriptWebhookNoPhoneReturnsNotFound(): void
    {
        $result = $this->dispatchJob(
            ProcessElevenLabsTranscriptWebhookJob::class,
            [
                'data' => [
                    'conversation_id' => 'conv_nophone_' . Str::random(10),
                    'transcript' => [],
                    'metadata' => ['phone_call' => null],
                    'analysis' => ['data_collection_results' => []],
                ],
            ]
        );

        $this->assertIsArray($result);
        $this->assertStringContainsString('No phone number', $result['message']);
    }

    public function testCalendarEventWebhookCreatesEvent(): void
    {
        $this->markTestSkipped('Requires Event domain defaults (Theme, EventType, EventCategory, etc.)');
    }

    public function testCalendarEventWebhookUpdatesPeopleInfo(): void
    {
        $this->markTestSkipped('Requires Event domain defaults (Theme, EventType, EventCategory, etc.)');
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

    public function testCalendarEventWebhookCreatesLeadWhenMissing(): void
    {
        $this->markTestSkipped('Requires Event domain defaults (Theme, EventType, EventCategory, etc.)');
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

    public function testHandOffWebhookCreatesLeadWhenMissing(): void
    {
        $newPhone = '+1809' . random_int(1000000, 9999999);

        $result = $this->dispatchJob(
            ProcessElevenLabsHandOffWebhookJob::class,
            ['phone' => $newPhone]
        );

        $this->assertIsArray($result);
        $this->assertEquals('Handoff triggered', $result['message']);
        $this->assertArrayHasKey('lead_id', $result);
        $this->assertNotEmpty($result['lead_id']);
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

        $result = $job->handle();

        $this->assertIsArray($result, 'Webhook job returned null — check webhookRequest status for exception details');

        return $result;
    }
}
