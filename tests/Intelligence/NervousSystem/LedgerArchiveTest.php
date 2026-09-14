<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\Actions\ArchiveOldEventsAction;
use Kanvas\NervousSystem\Ledger\Actions\RestoreEventsFromArchiveAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;
use Tests\TestCase;

class LedgerArchiveTest extends TestCase
{
    /**
     * The sweep is deliberately table-wide — no app, company or event-type scoping — so a cutoff
     * anywhere near `now` deletes ledger rows a parallel test class is about to assert on, and the
     * failure surfaces over there (PlanAgentWakeUpTest, BuildLeadFollowUpDailySummaryActionTest,
     * EventAnalyticsServiceTest). Seed 5 years back, cut at 3: a window nothing else occupies.
     */
    private const int RETENTION_DAYS = 365 * 3;

    private function ancientTimestamp(int $extraDaysBack = 0): Carbon
    {
        return Carbon::now()->subYears(5)->subDays($extraDaysBack);
    }

    public function testArchiveFlushesEventsOlderThanRetentionWindow(): void
    {
        Storage::fake('local');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'archive-test-' . uniqid();

        for ($i = 0; $i < 3; $i++) {
            new AppendEventAction(
                new EventData(
                    app: $app,
                    company: $company,
                    sourceDomain: 'TestDomain',
                    eventType: $tag,
                    status: EventStatusEnum::INFO,
                    payload: ['n' => $i],
                    occurredAt: $this->ancientTimestamp(),
                ),
            )->execute();
        }

        $beforeArchive = Event::query()->where('event_type', $tag)->count();
        $this->assertSame(3, $beforeArchive);

        $result = new ArchiveOldEventsAction(
            retentionDaysOverride: self::RETENTION_DAYS,
            diskOverride: 'local',
        )->execute();

        $this->assertGreaterThanOrEqual(3, $result['event_count']);
        $this->assertNotEmpty($result['s3_path']);
        $this->assertGreaterThan(0, $result['size_bytes']);

        $afterArchive = Event::query()->where('event_type', $tag)->count();
        $this->assertSame(0, $afterArchive, 'Archived events must be removed from MySQL');

        Storage::disk('local')->assertExists($result['s3_path']);

        $archive = EventArchive::find($result['archive_id']);
        $this->assertNotNull($archive);
        $this->assertSame($result['s3_path'], $archive->s3_path);
        $this->assertSame('local', $archive->s3_disk);
        $this->assertSame($result['event_count'], $archive->event_count);
    }

    public function testArchiveSkipsRecentEventsInsideRetentionWindow(): void
    {
        Storage::fake('local');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'archive-recent-' . uniqid();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                occurredAt: Carbon::now(),
            ),
        )->execute();

        new ArchiveOldEventsAction(
            retentionDaysOverride: self::RETENTION_DAYS,
            diskOverride: 'local',
        )->execute();

        $stillThere = Event::query()->where('event_type', $tag)->count();
        $this->assertSame(1, $stillThere, 'Events inside retention window must NOT be archived');
    }

    public function testArchiveReturnsEmptyResultWhenNothingToArchive(): void
    {
        Storage::fake('local');

        $result = new ArchiveOldEventsAction(
            retentionDaysOverride: 365 * 100,
            diskOverride: 'local',
        )->execute();

        $this->assertSame(0, $result['event_count']);
        $this->assertArrayNotHasKey('archive_id', $result);
    }

    public function testConsoleCommandRunsArchiveWithOverrides(): void
    {
        Storage::fake('local');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'archive-cmd-' . uniqid();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                occurredAt: $this->ancientTimestamp(),
            ),
        )->execute();

        $exitCode = $this->artisan(
            'nervous-system:archive-old-ledger-events',
            ['--retention-days' => self::RETENTION_DAYS, '--disk' => 'local'],
        )->run();

        $this->assertSame(0, $exitCode);

        $remaining = Event::query()->where('event_type', $tag)->count();
        $this->assertSame(0, $remaining);
    }

    public function testConfigPreservesPeopleEnrichedByDefault(): void
    {
        $this->assertContains(
            'people.enriched',
            (array) config('nervous-system.ledger.preserve_event_types'),
            'people.enriched must ship in the default preserve list so the enrichment feed is never swept',
        );
    }

    public function testConfigPreservesPeopleEmailValidatedByDefault(): void
    {
        $this->assertContains(
            'people.email_validated',
            (array) config('nervous-system.ledger.preserve_event_types'),
            'people.email_validated must ship in the default preserve list so the bounce/invalid-email export is never swept',
        );
    }

    public function testConfigPreservesAgentKnowledgeSavedByDefault(): void
    {
        $this->assertContains(
            'agent.knowledge.saved',
            (array) config('nervous-system.ledger.preserve_event_types'),
            'agent.knowledge.saved backs agent long-term memory (the remember tool) and must never be swept',
        );
    }

    public function testArchiveNeverSweepsPreservedEventTypes(): void
    {
        Storage::fake('local');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $keepTag = 'preserve-keep-' . uniqid();
        $sweepTag = 'archive-sweep-' . uniqid();

        foreach ([$keepTag, $sweepTag] as $tag) {
            new AppendEventAction(
                new EventData(
                    app: $app,
                    company: $company,
                    sourceDomain: 'TestDomain',
                    eventType: $tag,
                    status: EventStatusEnum::INFO,
                    occurredAt: $this->ancientTimestamp(),
                ),
            )->execute();
        }

        new ArchiveOldEventsAction(
            retentionDaysOverride: self::RETENTION_DAYS,
            diskOverride: 'local',
            preserveEventTypesOverride: [$keepTag],
        )->execute();

        $this->assertSame(
            1,
            Event::query()->where('event_type', $keepTag)->count(),
            'Preserved event types must survive the sweep regardless of age',
        );
        $this->assertSame(
            0,
            Event::query()->where('event_type', $sweepTag)->count(),
            'Non-preserved events are still archived and deleted',
        );
    }

    public function testRestoreRehydratesArchivedEventsAndIsIdempotent(): void
    {
        Storage::fake('local');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'people.enriched';
        $marker = 'restore-' . uniqid();

        $uuids = [];
        for ($i = 0; $i < 3; $i++) {
            $event = new AppendEventAction(
                new EventData(
                    app: $app,
                    company: $company,
                    sourceDomain: 'TestDomain',
                    eventType: $tag,
                    status: EventStatusEnum::INFO,
                    payload: [
                        'marker' => $marker,
                        'changes' => ['title' => ['from' => 'Old', 'to' => 'New']],
                        'changed_fields' => ['title'],
                    ],
                    occurredAt: $this->ancientTimestamp(),
                ),
            )->execute();
            $uuids[] = $event->uuid;
        }

        // Force the sweep to flush people.enriched (empty preserve list) so we
        // reproduce the historical loss this restore path exists to undo.
        new ArchiveOldEventsAction(
            retentionDaysOverride: self::RETENTION_DAYS,
            diskOverride: 'local',
            preserveEventTypesOverride: [],
        )->execute();

        foreach ($uuids as $uuid) {
            $this->assertSame(0, Event::query()->where('uuid', $uuid)->count());
        }

        $result = new RestoreEventsFromArchiveAction(
            eventTypes: [$tag],
            appId: $app->getId(),
            companyId: $company->getId(),
            diskOverride: 'local',
        )->execute();

        $this->assertGreaterThanOrEqual(3, $result['restored']);

        foreach ($uuids as $uuid) {
            $restored = Event::query()->where('uuid', $uuid)->first();
            $this->assertNotNull($restored, 'archived event should be back in MySQL');
            $this->assertSame($marker, $restored->payload['marker']);
            $this->assertSame(1, $restored->change_count, 'materialized counts survive the round-trip');
        }

        $second = new RestoreEventsFromArchiveAction(
            eventTypes: [$tag],
            appId: $app->getId(),
            companyId: $company->getId(),
            diskOverride: 'local',
        )->execute();

        $this->assertSame(0, $second['restored'], 're-running restore must not duplicate rows');
        $this->assertGreaterThanOrEqual(3, $second['skipped_existing']);
    }

    public function testRestoreHonorsDateRange(): void
    {
        Storage::fake('local');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'people.enriched';
        $marker = 'daterange-' . uniqid();
        // A day of its own — the restore filter is day-granular, and the previous test leaves its
        // own restored people.enriched rows sitting on the default ancient day.
        $targetDay = $this->ancientTimestamp(30)->startOfDay()->addHours(9);

        $inRange = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                payload: ['marker' => $marker, 'when' => 'in'],
                occurredAt: $targetDay,
            ),
        )->execute();

        $outOfRange = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                payload: ['marker' => $marker, 'when' => 'out'],
                occurredAt: $targetDay->copy()->subDays(5),
            ),
        )->execute();

        new ArchiveOldEventsAction(
            retentionDaysOverride: self::RETENTION_DAYS,
            diskOverride: 'local',
            preserveEventTypesOverride: [],
        )->execute();

        $result = new RestoreEventsFromArchiveAction(
            eventTypes: [$tag],
            appId: $app->getId(),
            companyId: $company->getId(),
            diskOverride: 'local',
            fromDate: $targetDay->toDateString(),
            toDate: $targetDay->toDateString(),
        )->execute();

        $this->assertSame(1, Event::query()->where('uuid', $inRange->uuid)->count(), 'event inside the day is restored');
        $this->assertSame(0, Event::query()->where('uuid', $outOfRange->uuid)->count(), 'event outside the range stays archived');
        $this->assertSame(1, $result['restored']);
    }
}
