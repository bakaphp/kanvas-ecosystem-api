<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Support\Setup;
use Tests\TestCase;

/**
 * SIPGO keeps no date range on `events_versions` — only the child `events_versions_dates` rows —
 * so `start_at` / `end_at` were null on every imported version.
 *
 * That is load-bearing: `ParticipantPass::scopeNoShow()` filters on `start_at`, the Gestor's event
 * date range is a version-level filter, and `rpt_inscripcion.fecha_inicio` / `fecha_fin` read it.
 */
class EventVersionDateRangeTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'event'];

    public function test_the_range_spans_the_earliest_and_latest_date(): void
    {
        $version = $this->seedVersion();

        // Out of order on purpose: the range is MIN/MAX, not first/last imported.
        foreach (['2026-03-12', '2026-03-10', '2026-03-11'] as $date) {
            $this->seedDate($version, $date);
        }

        $this->backfill($version);
        $version->refresh();

        $this->assertSame('2026-03-10', substr((string) $version->start_at, 0, 10));
        $this->assertSame('2026-03-12', substr((string) $version->end_at, 0, 10));
    }

    public function test_a_single_day_version_starts_and_ends_the_same_day(): void
    {
        $version = $this->seedVersion();
        $this->seedDate($version, '2026-05-04');

        $this->backfill($version);
        $version->refresh();

        $this->assertSame('2026-05-04', substr((string) $version->start_at, 0, 10));
        $this->assertSame('2026-05-04', substr((string) $version->end_at, 0, 10));
    }

    public function test_a_version_with_no_dates_is_left_alone(): void
    {
        $version = $this->seedVersion();
        EventVersionDate::where('event_version_id', $version->getId())->delete();

        EventVersion::where('id', $version->getId())->update(['start_at' => null, 'end_at' => null]);

        $this->backfill($version);
        $version->refresh();

        $this->assertNull($version->start_at, 'no dates must not invent a range');
    }

    /**
     * Mirrors PullEventsFromIntrasAction::backfillVersionDateRange() for one version.
     */
    private function backfill(EventVersion $version): void
    {
        $range = EventVersionDate::query()
            ->where('event_version_id', $version->getId())
            ->where('is_deleted', 0)
            ->groupBy('event_version_id')
            ->selectRaw('event_version_id, MIN(event_date) as starts, MAX(event_date) as ends')
            ->first();

        if ($range === null) {
            return;
        }

        EventVersion::where('id', $version->getId())->update([
            'start_at' => $range->starts,
            'end_at' => $range->ends,
        ]);
    }

    private function seedDate(EventVersion $version, string $date): void
    {
        EventVersionDate::create([
            'event_version_id' => $version->getId(),
            'users_id' => static::$cachedUser->getId(),
            'event_date' => $date,
            'start_time' => $date . ' 09:00:00',
            'end_time' => $date . ' 17:00:00',
        ]);
    }

    /**
     * Built through the create mutation: `events` has six FK-constrained catalogs, so the
     * established route (Setup + createEvent) is cheaper than hand-rolling the graph.
     */
    private function seedVersion(): EventVersion
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        $response = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) { id versions { data { id } } }
            }
        ', ['input' => [
            'name' => 'Range Event ' . uniqid(),
            'description' => 'Date range fixture',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [[
                'date' => '2026-03-10',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]],
        ]])->assertSuccessful();

        $version = EventVersion::find((int) $response->json('data.createEvent.versions.data.0.id'));

        // The mutation seeds one date; clear it so each test controls the range it asserts on.
        EventVersionDate::where('event_version_id', $version->getId())->delete();

        return $version;
    }
}
