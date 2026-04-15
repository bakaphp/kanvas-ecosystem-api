<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class HandOffActionTest extends TestCase
{
    private ?Lead $lead = null;

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

    public function testHandOffDefaultsToHuman(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $result = new HandOffAction($lead, $app)->execute();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Handoff processed successfully', $result['message']);

        $lead->refresh();
        $this->assertEquals(1, $lead->get(ConfigurationEnum::AGENT_HAND_OFF->value));
        $this->assertEquals('human', $lead->get(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value));
    }

    public function testHandOffWithServiceType(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $result = new HandOffAction($lead, $app, [
            'handoff_type' => 'service',
        ])->execute();

        $this->assertTrue($result['success']);

        $lead->refresh();
        $this->assertEquals('service', $lead->get(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value));

        $serviceType = LeadType::where('apps_id', $app->getId())
            ->where('companies_id', $lead->companies_id)
            ->where('name', 'Service')
            ->where('is_deleted', 0)
            ->first();

        $this->assertNotNull($serviceType);
        $this->assertEquals($serviceType->getId(), $lead->leads_types_id);
    }

    public function testHandOffWithConversationSummary(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $result = new HandOffAction($lead, $app, [
            'handoff_type' => 'human',
            'conversation_summary' => 'Customer wants financing options.',
        ])->execute();

        $this->assertTrue($result['success']);
    }

    public function testHandOffDeduplication(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        $params = [
            'handoff_type' => 'human',
            'conversation_summary' => 'Dedup test',
        ];

        $result1 = new HandOffAction($lead, $app, $params)->execute();
        $this->assertTrue($result1['success']);
        $this->assertArrayNotHasKey('duplicate', $result1);

        $result2 = new HandOffAction($lead, $app, $params)->execute();
        $this->assertTrue($result2['success']);
        $this->assertTrue($result2['duplicate']);
    }

    public function testHandOffSetsCustomFields(): void
    {
        Notification::fake();
        $lead = $this->createTestLead();
        $app = app(Apps::class);

        new HandOffAction($lead, $app, [
            'handoff_type' => 'human',
        ])->execute();

        $lead->refresh();
        $this->assertEquals(1, $lead->get(ConfigurationEnum::AGENT_HAND_OFF->value));
        $this->assertEquals('human', $lead->get(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value));
    }

    private function createTestLead(): Lead
    {
        if ($this->lead !== null) {
            return $this->lead;
        }

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $branch = $company->defaultBranch;

        $contactData = [
            [
                'value' => '+1809' . rand(1000000, 9999999),
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ],
        ];

        $peopleDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'HandOff',
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Test',
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
            title: 'HandOff Test Lead',
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

        $this->lead = new CreateLeadAction($leadData)->execute();

        return $this->lead;
    }
}
