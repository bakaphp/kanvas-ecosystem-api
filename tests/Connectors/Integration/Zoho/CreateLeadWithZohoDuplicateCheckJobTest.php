<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Jobs\CreateLeadWithZohoDuplicateCheckJob;
use Kanvas\Enums\StateEnums;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\Models\LeadRotationAgent;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use RuntimeException;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;
use Webleit\ZohoCrmApi\RecordCollection;

final class CreateLeadWithZohoDuplicateCheckJobTest extends TestCase
{
    use DatabaseTransactions;

    private const string ZOHO_OWNER_ID = '5550000000123456';

    protected array $connectionsToTransact = ['mysql', 'crm', 'social', 'workflow', 'ecosystem'];

    private ReceiverWebhook $receiver;
    private LeadReceiver $leadReceiver;
    private Users $rotationAgent;
    private LeadRotation $rotation;

    /** @var int[] lead ids whose Zoho deal the job asked to pull */
    public array $convertedDealPulls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Type ' . Str::random(8),
            'description' => 'Test',
            'is_active' => true,
            'uuid' => Str::uuid(),
        ]);

        $leadSource = LeadSource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Apply3 ' . Str::random(8),
            'description' => 'Test',
            'is_active' => true,
            'uuid' => Str::uuid(),
            'leads_types_id' => $leadType->getId(),
        ]);

        $this->rotationAgent = Users::factory()->create();
        $company->associateUser($this->rotationAgent, StateEnums::YES->getValue(), $company->defaultBranch);
        $company->associateUserApp($this->rotationAgent, $app, StateEnums::YES->getValue());
        $this->rotation = LeadRotation::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Rotation ' . Str::random(8),
            'hits' => 0,
            'leads_rotations_email' => '',
        ]);
        LeadRotationAgent::create([
            'leads_rotations_id' => $this->rotation->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->rotationAgent->getId(),
            'percent' => 100,
        ]);

        $this->leadReceiver = LeadReceiver::create([
            'name' => 'Apply3 ' . Str::random(8),
            'users_id' => $user->getId(),
            'agents_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'rotations_id' => $this->rotation->getId(),
            'leads_sources_id' => $leadSource->getId(),
            'lead_types_id' => $leadType->getId(),
            'source_name' => 'apply3',
            'is_default' => false,
        ]);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => CreateLeadWithZohoDuplicateCheckJob::class],
            ['name' => 'Zoho Lead Receiver With Duplicate Check'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
            ]);
        $this->setUpDuplicateConfiguration();
    }

    public function testANewContactFollowsTheStandardFlow(): void
    {
        $result = $this->runReceiver($this->payload($this->uniqueEmail()), $this->zohoWithNoMatches());

        $lead = Lead::find($result['lead_id']);
        $this->assertSame(CreateLeadWithZohoDuplicateCheckJob::STATUS_CLEAR, $result['duplicate_check']['status']);
        $this->assertSame($this->leadReceiver->leads_sources_id, $lead->leads_sources_id);
        $this->assertSame($this->rotationAgent->getId(), $lead->leads_owner_id);
        $this->assertNull($lead->get(CustomFieldEnum::ZOHO_DUPLICATE_OWNER_ID->value));
    }

    public function testAZohoOnlyMatchIsPulledAndReassignedWithoutCreatingALead(): void
    {
        $email = $this->uniqueEmail();
        $zohoLeadId = '5725' . random_int(100000000000, 999999999999);
        $module = $this->module();
        $module->expects($this->once())
            ->method('searchEmail')
            ->with($email)
            ->willReturn($this->records($module, [
                'id' => $zohoLeadId,
                'First_Name' => 'Dana',
                'Last_Name' => 'Original',
                'Email' => strtoupper($email),
                'Lead_Status' => 'Contacted',
            ]));
        $module->expects($this->never())->method('searchPhone');
        $module->expects($this->once())
            ->method('update')
            ->with($zohoLeadId, ['Owner' => self::ZOHO_OWNER_ID]);

        $result = $this->runReceiver($this->payload($email), $module);

        $this->assertSame(CreateLeadWithZohoDuplicateCheckJob::STATUS_MATCHED, $result['duplicate_check']['status']);
        $this->assertSame([$zohoLeadId], $result['duplicate_check']['zoho_ids']);
        $this->assertSame([$result['lead_id']], $result['duplicate_check']['pulled_lead_ids']);
        $this->assertTrue($result['zoho_updated']);
        $this->assertSame(1, $this->receiverLeadCount());
        $this->assertReassigned(Lead::find($result['lead_id']));
        $this->assertSame($zohoLeadId, (string) Lead::find($result['lead_id'])->get(CustomFieldEnum::ZOHO_LEAD_ID->value));
        $this->assertSame(0, (int) $this->rotation->fresh()->hits);
    }

    public function testAZohoLeadAlreadyConvertedToADealIsMatchedAndLeftWithItsOwner(): void
    {
        $email = $this->uniqueEmail();
        $zohoLeadId = '5725' . random_int(100000000000, 999999999999);
        $module = $this->module();
        $module->expects($this->once())
            ->method('searchEmail')
            ->with($email, ['converted' => 'both'])
            ->willReturn($this->records($module, [
                'id' => $zohoLeadId,
                'First_Name' => 'Dana',
                'Last_Name' => 'Converted',
                'Email' => $email,
                'Lead_Status' => 'Contacted',
                '$converted' => true,
            ]));
        $module->expects($this->never())->method('update');
        // A pulled lead defaults to the company user, who is also the duplicate owner in setUp.
        $this->receiver->update([
            'configuration' => [
                'receiver_id' => $this->leadReceiver->getId(),
                'duplicate_owner_users_id' => $this->rotationAgent->getId(),
                'duplicate_zoho_owner_id' => self::ZOHO_OWNER_ID,
            ],
        ]);

        $result = $this->runReceiver($this->payload($email), $module);

        $lead = Lead::find($result['lead_id']);
        $this->assertSame(CreateLeadWithZohoDuplicateCheckJob::STATUS_MATCHED, $result['duplicate_check']['status']);
        $this->assertTrue($result['duplicate_check']['converted']);
        $this->assertFalse($result['zoho_updated']);
        $this->assertSame(1, $this->receiverLeadCount());
        $this->assertSame($zohoLeadId, (string) $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value));
        $this->assertSame([$lead->getId()], $this->convertedDealPulls);
        $this->assertNotSame($this->rotationAgent->getId(), $lead->leads_owner_id);
        $this->assertNull($lead->get(CustomFieldEnum::ZOHO_DUPLICATE_OWNER_ID->value));
        $this->assertSame(0, (int) $this->rotation->fresh()->hits);
    }

    public function testAnOpenZohoLeadWinsOverAConvertedOne(): void
    {
        $email = $this->uniqueEmail();
        $module = $this->module();
        $module->method('searchEmail')->willReturn($this->records(
            $module,
            ['id' => 'zl-converted', 'Last_Name' => 'Old', 'Email' => $email, '$converted' => true],
            ['id' => 'zl-open', 'Last_Name' => 'Current', 'Email' => $email, '$converted' => false],
        ));
        $module->expects($this->once())
            ->method('update')
            ->with('zl-open', ['Owner' => self::ZOHO_OWNER_ID]);

        $result = $this->runReceiver($this->payload($email), $module);

        $this->assertSame(['zl-open'], $result['duplicate_check']['zoho_ids']);
        $this->assertFalse($result['duplicate_check']['converted']);
        $this->assertSame([], $this->convertedDealPulls);
        $this->assertReassigned(Lead::find($result['lead_id']));
    }

    public function testAnExistingKanvasLeadIsReassignedInKanvasAndZohoWithoutAskingZoho(): void
    {
        $email = $this->uniqueEmail();
        $first = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());
        $existing = Lead::find($first['lead_id']);
        $existing->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-9');
        $existing->close();

        $module = $this->module();
        $module->expects($this->never())->method('searchEmail');
        $module->expects($this->once())
            ->method('update')
            ->with('zl-9', ['Owner' => self::ZOHO_OWNER_ID]);

        $result = $this->runReceiver($this->payload($email), $module);

        $this->assertSame([$first['lead_id']], $result['duplicate_check']['kanvas_lead_ids']);
        $this->assertSame($first['lead_id'], $result['lead_id']);
        $this->assertSame('zl-9', $result['zoho_lead_id']);
        $this->assertSame(1, $this->receiverLeadCount());
        $this->assertReassigned($existing->fresh());
        $this->assertSame($this->leadReceiver->leads_sources_id, $existing->fresh()->leads_sources_id);
    }

    public function testTheSubmissionOriginIsAppendedToTheDescriptionOnBothSidesOnce(): void
    {
        $email = $this->uniqueEmail();
        $first = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());
        $existing = Lead::find($first['lead_id']);
        $existing->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-11');
        $origin = 'New submission from ' . $this->leadReceiver->name . ' on ' . now()->format('m/d/Y') . '.';

        $module = $this->module();
        $module->method('get')->willReturnOnConsecutiveCalls(
            new Record(['id' => 'zl-11', 'Description' => 'Edited in Zoho'], $module),
            new Record(['id' => 'zl-11', 'Description' => "Edited in Zoho\n" . $origin], $module),
        );
        $module->expects($this->exactly(2))
            ->method('update')
            ->with('zl-11', ['Owner' => self::ZOHO_OWNER_ID, 'Description' => "Edited in Zoho\n" . $origin]);

        $this->runReceiver($this->payload($email), $module);
        $this->runReceiver($this->payload($email), $module);

        $this->assertSame(1, substr_count((string) $existing->fresh()->description, $origin));
    }

    public function testAFailedDescriptionReadStillReassignsTheOwnerInZoho(): void
    {
        $email = $this->uniqueEmail();
        $first = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());
        Lead::find($first['lead_id'])->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-12');

        $module = $this->module();
        $module->method('get')->willThrowException(new RuntimeException('Zoho is down'));
        $module->expects($this->once())
            ->method('update')
            ->with('zl-12', ['Owner' => self::ZOHO_OWNER_ID]);

        $result = $this->runReceiver($this->payload($email), $module);

        $this->assertTrue($result['zoho_updated']);
    }

    public function testAKanvasPhoneMatchIgnoresFormatting(): void
    {
        $digits = $this->uniquePhoneDigits();
        $first = $this->runReceiver($this->payload($this->uniqueEmail(), $digits), $this->zohoWithNoMatches());

        $module = $this->module();
        $module->expects($this->never())->method('searchPhone');

        $formatted = '+1 (' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        $result = $this->runReceiver($this->payload($this->uniqueEmail(), $formatted), $module);

        $this->assertSame([$first['lead_id']], $result['duplicate_check']['kanvas_lead_ids']);
        $this->assertReassigned(Lead::find($first['lead_id']));
    }

    public function testTheMostRecentMatchIsTheOneReassigned(): void
    {
        $email = $this->uniqueEmail();
        $older = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());
        $this->receiver->update(['configuration' => ['receiver_id' => $this->leadReceiver->getId()]]);
        $newer = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());
        $this->setUpDuplicateConfiguration();

        $result = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());

        $this->assertSame($newer['lead_id'], $result['lead_id']);
        $this->assertSame($this->rotationAgent->getId(), Lead::find($older['lead_id'])->leads_owner_id);
    }

    public function testAKanvasMatchNotYetInZohoKeepsTheOwnerForItsQueuedPush(): void
    {
        $email = $this->uniqueEmail();
        $first = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());

        $module = $this->module();
        $module->expects($this->never())->method('update');

        $result = $this->runReceiver($this->payload($email), $module);

        $this->assertNull($result['zoho_lead_id']);
        $this->assertFalse($result['zoho_updated']);
        $this->assertReassigned(Lead::find($first['lead_id']));
    }

    public function testAFailedZohoUpdateStillReassignsTheKanvasLead(): void
    {
        $email = $this->uniqueEmail();
        $first = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());
        Lead::find($first['lead_id'])->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-10');

        $module = $this->stub();
        $module->method('update')->willThrowException(new RuntimeException('Zoho is down'));

        $result = $this->runReceiver($this->payload($email), $module);

        $this->assertFalse($result['zoho_updated']);
        $this->assertReassigned(Lead::find($first['lead_id']));
    }

    public function testAZohoPhoneStoredInAnotherFormatStillMatches(): void
    {
        $digits = $this->uniquePhoneDigits();
        $stored = '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        $module = $this->stub();
        $module->method('searchEmail')->willReturn(new RecordCollection());
        $module->method('searchPhone')->willReturnCallback(
            fn (string $variant) => $variant === $stored
                ? $this->records($module, ['id' => 'zl-2', 'Mobile' => $stored])
                : new RecordCollection()
        );

        $result = $this->runReceiver($this->payload($this->uniqueEmail(), '+1 ' . $digits), $module);

        $this->assertSame(CreateLeadWithZohoDuplicateCheckJob::STATUS_MATCHED, $result['duplicate_check']['status']);
        $this->assertSame(['zl-2'], $result['duplicate_check']['zoho_ids']);
    }

    public function testAPartialPhoneMatchFromZohoIsIgnored(): void
    {
        $digits = $this->uniquePhoneDigits();
        $module = $this->stub();
        $module->method('searchEmail')->willReturn(new RecordCollection());
        $module->method('searchPhone')->willReturnCallback(
            fn () => $this->records($module, ['id' => 'zl-3', 'Phone' => substr($digits, 0, 6) . '0000'])
        );

        $result = $this->runReceiver($this->payload($this->uniqueEmail(), $digits), $module);

        $this->assertSame(CreateLeadWithZohoDuplicateCheckJob::STATUS_CLEAR, $result['duplicate_check']['status']);
        $this->assertSame($this->rotationAgent->getId(), Lead::find($result['lead_id'])->leads_owner_id);
    }

    public function testAFailedZohoSearchStillCreatesTheLeadTheStandardWay(): void
    {
        $module = $this->stub();
        $module->method('searchEmail')->willThrowException(new RuntimeException('Zoho is down'));

        $result = $this->runReceiver($this->payload($this->uniqueEmail()), $module);

        $lead = Lead::find($result['lead_id']);
        $this->assertSame(CreateLeadWithZohoDuplicateCheckJob::STATUS_FAILED, $result['duplicate_check']['status']);
        $this->assertSame($this->leadReceiver->leads_sources_id, $lead->leads_sources_id);
        $this->assertSame($this->rotationAgent->getId(), $lead->leads_owner_id);
    }

    public function testWithoutTheZohoOwnerIdItIsTheStandardReceiver(): void
    {
        $this->receiver->update(['configuration' => ['receiver_id' => $this->leadReceiver->getId()]]);
        $module = $this->module();
        $module->expects($this->never())->method('searchEmail');

        $result = $this->runReceiver($this->payload($this->uniqueEmail()), $module);

        $this->assertNull($result['duplicate_check']);
        $this->assertSame($this->rotationAgent->getId(), Lead::find($result['lead_id'])->leads_owner_id);
    }

    public function testFillEmptyOnlyWritesOnlyTheFieldsTheExistingLeadHasEmpty(): void
    {
        $email = $this->uniqueEmail();
        $phone = $this->uniquePhoneDigits();
        $existing = $this->existingFillOnlyLead($email, 'zl-20');
        $existing->set('loan_amount', '5000');
        $origin = 'New submission from ' . $this->leadReceiver->name . ' on ' . now()->format('m/d/Y') . '.';

        $module = $this->module();
        $module->method('get')->willReturn(new Record([
            'id' => 'zl-20',
            'First_Name' => 'Dana',
            'Last_Name' => 'Original',
            'Email' => $email,
            'Loan_Amount' => '9000',
            'Business_Name' => null,
            'Industry' => '-None-',
        ], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zl-20', [
                'Business_Name' => 'Acme',
                'Industry' => 'Retail',
                'Phone' => $phone,
                'Description' => $origin,
            ]);

        $result = $this->runReceiver(
            $this->payload($email, $phone, [
                'loan_amount' => '7000',
                'business_name' => 'Acme',
                'industry' => 'Retail',
            ]),
            $module
        );

        $lead = $existing->fresh();
        $this->assertTrue($result['zoho_updated']);
        $this->assertSame(1, $this->receiverLeadCount());
        $this->assertSame('5000', (string) $lead->get('loan_amount'));
        $this->assertSame('Acme', $lead->get('business_name'));
        $this->assertSame('Retail', $lead->get('industry'));
        $this->assertSame($this->rotationAgent->getId(), $lead->leads_owner_id);
        $this->assertNull($lead->get(CustomFieldEnum::ZOHO_DUPLICATE_OWNER_ID->value));
        $this->assertSame(
            ['Business_Name', 'Industry', 'Phone'],
            array_keys($lead->get(CustomFieldEnum::ZOHO_LEAD_SYNCED_FIELDS->value)['fields'])
        );
    }

    public function testFillEmptyOnlyStillReassignsTheOwnerWhenTheZohoOwnerIsConfigured(): void
    {
        $email = $this->uniqueEmail();
        $this->existingFillOnlyLead($email, 'zl-21');
        $this->receiver->update([
            'configuration' => [
                ...$this->receiver->configuration,
                'duplicate_owner_users_id' => auth()->user()->getId(),
                'duplicate_zoho_owner_id' => self::ZOHO_OWNER_ID,
            ],
        ]);

        $module = $this->module();
        $module->method('get')->willReturn(new Record([
            'id' => 'zl-21',
            'Description' => 'Edited in Zoho',
            'Business_Name' => 'Kept',
        ], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zl-21', $this->callback(
                fn (array $data) => $data['Owner'] === self::ZOHO_OWNER_ID && ! array_key_exists('Business_Name', $data)
            ));

        $result = $this->runReceiver($this->payload($email, customFields: ['business_name' => 'Acme']), $module);

        $this->assertReassigned(Lead::find($result['lead_id']));
    }

    public function testFillEmptyOnlyWritesNothingWhenZohoCannotBeRead(): void
    {
        $email = $this->uniqueEmail();
        $this->existingFillOnlyLead($email, 'zl-22');

        $module = $this->module();
        $module->method('get')->willThrowException(new RuntimeException('Zoho is down'));
        $module->expects($this->never())->method('update');

        $result = $this->runReceiver($this->payload($email, customFields: ['business_name' => 'Acme']), $module);

        $this->assertFalse($result['zoho_updated']);
    }

    private function existingFillOnlyLead(string $email, string $zohoLeadId): Lead
    {
        $this->receiver->update([
            'configuration' => [
                'receiver_id' => $this->leadReceiver->getId(),
                'zoho_fill_empty_only' => true,
            ],
        ]);
        auth()->user()->getCurrentCompany()->set(CustomFieldEnum::FIELDS_MAP->value, [
            'loan_amount' => 'Loan_Amount',
            'business_name' => 'Business_Name',
            'industry' => 'Industry',
        ]);

        $first = $this->runReceiver($this->payload($email), $this->zohoWithNoMatches());
        $lead = Lead::find($first['lead_id']);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, $zohoLeadId);

        return $lead;
    }

    private function setUpDuplicateConfiguration(): void
    {
        $this->receiver->update([
            'configuration' => [
                'receiver_id' => $this->leadReceiver->getId(),
                'duplicate_owner_users_id' => auth()->user()->getId(),
                'duplicate_zoho_owner_id' => self::ZOHO_OWNER_ID,
            ],
        ]);
    }

    private function assertReassigned(Lead $lead): void
    {
        $this->assertSame(auth()->user()->getId(), $lead->leads_owner_id);
        $this->assertSame(self::ZOHO_OWNER_ID, (string) $lead->get(CustomFieldEnum::ZOHO_DUPLICATE_OWNER_ID->value));
    }

    private function receiverLeadCount(): int
    {
        return Lead::query()->where('leads_receivers_id', $this->leadReceiver->getId())->notDeleted()->count();
    }

    private function runReceiver(array $payload, ZohoRecordsModule $module): array
    {
        $webhookRequest = new ProcessWebhookAttemptAction(
            $this->receiver->fresh(),
            Request::create('https://localhost/v1/receiver/' . $this->receiver->uuid, 'POST', $payload),
        )->execute();

        Queue::fake();

        $result = $this->job($webhookRequest, $module)->handle();

        if ($result === null) {
            $this->fail('Job returned null: ' . json_encode($webhookRequest->fresh()->exception));
        }

        return $result;
    }

    private function job(ReceiverWebhookCall $webhookRequest, ZohoRecordsModule $module): CreateLeadWithZohoDuplicateCheckJob
    {
        return new class ($webhookRequest, $module, $this) extends CreateLeadWithZohoDuplicateCheckJob {
            public function __construct(
                ReceiverWebhookCall $webhookRequest,
                private ZohoRecordsModule $module,
                private CreateLeadWithZohoDuplicateCheckJobTest $test
            ) {
                parent::__construct($webhookRequest);
            }

            protected function zohoLeadsModule(): ZohoRecordsModule
            {
                return $this->module;
            }

            /**
             * The deal pull is covered by PullConvertedLeadDealActionTest.
             */
            protected function pullConvertedLeadDeal(Lead $lead): void
            {
                $this->test->convertedDealPulls[] = $lead->getId();
            }
        };
    }

    private function payload(string $email, ?string $phone = null, array $customFields = []): array
    {
        $contacts = [['value' => $email, 'weight' => 0, 'contacts_types_id' => ContactTypeEnum::EMAIL->value]];

        if ($phone !== null) {
            $contacts[] = ['value' => $phone, 'weight' => 0, 'contacts_types_id' => ContactTypeEnum::PHONE->value];
        }

        return [
            'title' => 'Apply3 ' . Str::random(6),
            'people' => [
                'firstname' => 'Dana',
                'lastname' => 'Applicant',
                'contacts' => $contacts,
            ],
            'pipeline_stage_id' => 0,
            'custom_fields' => $customFields,
        ];
    }

    private function module(): MockObject&ZohoRecordsModule
    {
        return $this->createMock(ZohoRecordsModule::class);
    }

    private function stub(): Stub&ZohoRecordsModule
    {
        return $this->createStub(ZohoRecordsModule::class);
    }

    private function zohoWithNoMatches(): ZohoRecordsModule
    {
        $module = $this->stub();
        $module->method('searchEmail')->willReturn(new RecordCollection());
        $module->method('searchPhone')->willReturn(new RecordCollection());

        return $module;
    }

    private function records(ZohoRecordsModule $module, array ...$rows): RecordCollection
    {
        return new RecordCollection(array_map(fn (array $row) => new Record($row, $module), $rows));
    }

    private function uniqueEmail(): string
    {
        return 'apply3-' . Str::lower(Str::random(12)) . '@example.com';
    }

    private function uniquePhoneDigits(): string
    {
        return '829' . random_int(1000000, 9999999);
    }
}
