<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\RestoreDeletedParentEventsAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Support\Setup;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Tests\TestCase;

/**
 * An agency's events are not just `events.agencies_id = X`.
 *
 * A live version can hang off an event that is soft-deleted in SIPGO, or off one belonging to a
 * different agency. `pullEventVersions()` drops any version whose parent was not imported and
 * takes its registrations with it — 6 versions and 69 registrations across the four agencies,
 * found only by reconciling against the legacy row counts.
 *
 * The importer therefore pulls a version's parent in regardless, and must store it live: a live
 * version under a flagged event hides its parent behind `Event`'s global `is_deleted = 0` scope,
 * and the non-null `EventVersion.event` fails every calendar query (KANVAS-ECOSYSTEM-5GS).
 *
 * The selection half is a legacy-database query and is verified against real data instead:
 * re-importing agency 3 moved events 40 → 42, versions 124 → 126 and registrations 2,891 → 2,892,
 * which is its exact legacy total.
 */
class DeletedEventReimportTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'event'];

    public function test_a_parent_flagged_by_an_earlier_run_is_found_instead_of_duplicated(): void
    {
        $slug = 'intras-deleted-parent-' . uniqid();

        $first = $this->importEvent($slug);
        Event::where('id', $first->getId())->update(['is_deleted' => 1]);

        $second = $this->importEvent($slug);

        $this->assertSame(
            $first->getId(),
            $second->getId(),
            'the global is_deleted scope must not hide the parent from the next run'
        );

        $this->assertSame(1, Event::withTrashed()->where('slug', $slug)->count(), 'exactly one row, not one per import run');
    }

    public function test_a_new_parent_is_stored_live(): void
    {
        $event = $this->importEvent('intras-live-parent-' . uniqid());

        $this->assertSame(0, (int) $event->is_deleted);
    }

    public function test_restore_undeletes_a_parent_that_still_has_a_live_version(): void
    {
        $version = $this->seedVersion();
        Event::where('id', $version->event_id)->update(['is_deleted' => 1]);

        $this->assertNull($version->fresh()->event, 'precondition: the flagged parent is hidden');

        $restored = new RestoreDeletedParentEventsAction(app(Apps::class), $version->company)->execute();

        $this->assertSame(1, $restored);
        $this->assertNotNull($version->fresh()->event);
    }

    public function test_restore_leaves_a_deleted_event_whose_versions_are_deleted(): void
    {
        $version = $this->seedVersion();
        $event = $version->event;
        $event->delete();

        $this->assertSame(
            1,
            (int) EventVersion::withTrashed()->where('id', $version->getId())->value('is_deleted'),
            'precondition: the cascade deleted the version'
        );

        new RestoreDeletedParentEventsAction(app(Apps::class), $version->company)->execute();

        $this->assertSame(1, (int) Event::withTrashed()->where('id', $event->getId())->value('is_deleted'));
    }

    /**
     * The same shape the importer uses: match on slug + tenant, always create live.
     */
    private function importEvent(string $slug): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        /** @var Event $event */
        $event = Event::withTrashed()->firstOrCreate([
            'slug' => $slug,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ], [
            'users_id' => $user->getId(),
            'is_deleted' => 0,
            'name' => 'Parent ' . $slug,
            'event_type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'event_class_id' => EventClass::fromCompany($company)->fromApp($app)->first()->getId(),
            'event_category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'event_status_id' => EventStatus::fromCompany($company)->fromApp($app)->first()->getId(),
            'theme_id' => Theme::fromCompany($company)->fromApp($app)->first()->getId(),
            'theme_area_id' => ThemeArea::fromCompany($company)->fromApp($app)->first()->getId(),
        ]);

        return $event;
    }

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
            'name' => 'Orphan Parent ' . uniqid(),
            'description' => 'Deleted parent fixture',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [[
                'date' => '2026-03-10',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]],
        ]])->assertSuccessful();

        return EventVersion::find((int) $response->json('data.createEvent.versions.data.0.id'));
    }
}
