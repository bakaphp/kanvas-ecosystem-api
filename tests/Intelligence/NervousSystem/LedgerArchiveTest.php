<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\Actions\ArchiveOldEventsAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;
use Tests\TestCase;

class LedgerArchiveTest extends TestCase
{
    public function testArchiveWithZeroRetentionFlushesEligibleEvents(): void
    {
        Storage::fake('local');

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tag = 'archive-test-' . uniqid();

        for ($i = 0; $i < 3; $i++) {
            new AppendEventAction(
                new EventData(
                    appsId: $app->getId(),
                    companiesId: $company->getId(),
                    sourceDomain: 'TestDomain',
                    eventType: $tag,
                    status: EventStatusEnum::INFO,
                    payload: ['n' => $i],
                    occurredAt: Carbon::now()->subMinutes(2),
                ),
            )->execute();
        }

        $beforeArchive = Event::query()->where('event_type', $tag)->count();
        $this->assertSame(3, $beforeArchive);

        $result = new ArchiveOldEventsAction(
            retentionDaysOverride: 0,
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
                appsId: $app->getId(),
                companiesId: $company->getId(),
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                occurredAt: Carbon::now(),
            ),
        )->execute();

        new ArchiveOldEventsAction(
            retentionDaysOverride: 7,
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
                appsId: $app->getId(),
                companiesId: $company->getId(),
                sourceDomain: 'TestDomain',
                eventType: $tag,
                status: EventStatusEnum::INFO,
                occurredAt: Carbon::now()->subMinutes(5),
            ),
        )->execute();

        $exitCode = $this->artisan(
            'nervous-system:archive-old-ledger-events',
            ['--retention-days' => 0, '--disk' => 'local'],
        )->run();

        $this->assertSame(0, $exitCode);

        $remaining = Event::query()->where('event_type', $tag)->count();
        $this->assertSame(0, $remaining);
    }
}
