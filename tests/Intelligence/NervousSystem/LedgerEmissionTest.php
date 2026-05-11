<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
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
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Jobs\AppendToLedgerJob;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class LedgerEmissionTest extends TestCase
{
    public function testAppendEventActionWritesRowToLedger(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $event = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'test.smoke',
                status: EventStatusEnum::INFO,
                payload: ['hello' => 'world'],
            ),
        )->execute();

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'id' => $event->id,
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'source_domain' => 'TestDomain',
                'event_type' => 'test.smoke',
                'status' => 'info',
            ],
            'intelligence',
        );

        $this->assertSame(['hello' => 'world'], $event->payload);
        $this->assertNotNull($event->uuid);
        $this->assertNotNull($event->occurred_at);
    }

    public function testAppendEventActionRecordsResultAndDuration(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $event = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'tool.executed',
                status: EventStatusEnum::SUCCESS,
                payload: ['tool_name' => 'fake_tool'],
                result: ['ok' => true, 'value' => 42],
                durationMs: 137,
                correlationId: 'corr-test-123',
            ),
        )->execute();

        $this->assertSame('success', $event->status);
        $this->assertSame(['ok' => true, 'value' => 42], $event->result);
        $this->assertSame(137, $event->duration_ms);
        $this->assertSame('corr-test-123', $event->correlation_id);
    }

    public function testTraitDispatchesCreatedEventWhenLeadIsCreated(): void
    {
        Bus::fake([AppendToLedgerJob::class]);

        $this->ensureLeadType();
        $lead = $this->createTestLead();

        Bus::assertDispatched(
            AppendToLedgerJob::class,
            fn (AppendToLedgerJob $job): bool => $job->eventType === 'created'
                && $job->sourceEntityType === Lead::class
                && $job->sourceEntityId === (int) $lead->getId()
                && (int) $job->app->getId() === (int) $lead->apps_id
                && (int) $job->company?->getId() === (int) $lead->companies_id
                && $job->sourceDomain === 'Guild',
        );
    }

    public function testTraitDispatchesUpdatedEventWithDiffWhenLeadChanges(): void
    {
        $this->ensureLeadType();
        $lead = $this->createTestLead();

        Bus::fake([AppendToLedgerJob::class]);

        $lead->title = 'Renamed Lead Title';
        $lead->save();

        Bus::assertDispatched(
            AppendToLedgerJob::class,
            function (AppendToLedgerJob $job) use ($lead): bool {
                if ($job->eventType !== 'updated') {
                    return false;
                }
                if ($job->sourceEntityId !== (int) $lead->getId()) {
                    return false;
                }
                if (! is_array($job->payload) || ! isset($job->payload['diff']['title'])) {
                    return false;
                }

                return $job->payload['diff']['title'][1] === 'Renamed Lead Title';
            },
        );
    }

    public function testTraitDoesNotDispatchUpdateWithNoChanges(): void
    {
        $this->ensureLeadType();
        $lead = $this->createTestLead();

        Bus::fake([AppendToLedgerJob::class]);

        $lead->save();

        Bus::assertNotDispatched(AppendToLedgerJob::class);
    }

    public function testEventTenantScopingPreventsCrossAppLeak(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'test.scope.mine',
            ),
        )->execute();

        // Foreign-tenant event inserted directly to bypass DTO model lookups.
        Event::query()->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'apps_id' => 999999,
            'companies_id' => 999999,
            'source_domain' => 'TestDomain',
            'event_type' => 'test.scope.other',
            'status' => 'info',
            'occurred_at' => now(),
            'indexed_at' => now(),
        ]);

        $myEvents = Event::query()
            ->where('apps_id', $app->getId())
            ->where('event_type', 'like', 'test.scope.%')
            ->get();

        $this->assertGreaterThanOrEqual(1, $myEvents->count());
        foreach ($myEvents as $e) {
            $this->assertSame($app->getId(), $e->apps_id);
            $this->assertNotSame('test.scope.other', $e->event_type);
        }

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['apps_id' => 999999, 'event_type' => 'test.scope.other'],
            'intelligence',
        );
    }

    private function ensureLeadType(): void
    {
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
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
            ],
        );
    }

    private function createTestLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $branch = $company->defaultBranch;

        $contactData = [
            [
                'value' => 'ledger-test-' . uniqid() . '@example.com',
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 100,
            ],
        ];

        $peopleDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'Ledger',
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Test ' . uniqid(),
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
            title: 'Ledger Test Lead ' . uniqid(),
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

        return new CreateLeadAction($leadData)->execute();
    }
}
