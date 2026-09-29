<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
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
 * The importer therefore pulls a version's parent in regardless, storing it with SIPGO's deleted
 * flag. That makes the lookup the risk this test covers: `Event` inherits a global
 * `is_deleted = 0` scope, so a plain `firstOrCreate()` cannot see the flagged parent it wrote
 * last run and would create a second copy every time.
 *
 * The selection half is a legacy-database query and is verified against real data instead:
 * re-importing agency 3 moved events 40 → 42, versions 124 → 126 and registrations 2,891 → 2,892,
 * which is its exact legacy total.
 */
class DeletedEventReimportTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'event'];

    public function test_a_deleted_event_is_found_again_instead_of_duplicated(): void
    {
        $slug = 'intras-deleted-parent-' . uniqid();

        $first = $this->importEvent($slug, deleted: true);

        $this->assertSame(1, (int) $first->is_deleted, 'a parent deleted in SIPGO is stored flagged');

        $second = $this->importEvent($slug, deleted: true);

        $this->assertSame(
            $first->getId(),
            $second->getId(),
            'the global is_deleted scope must not hide the parent from the next run'
        );

        $this->assertSame(1, $this->countBySlug($slug), 'exactly one row, not one per import run');
    }

    public function test_a_live_event_still_round_trips(): void
    {
        $slug = 'intras-live-parent-' . uniqid();

        $first = $this->importEvent($slug, deleted: false);
        $second = $this->importEvent($slug, deleted: false);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(0, (int) $first->is_deleted);
    }

    /**
     * The same shape the importer uses: match on slug + tenant, carry SIPGO's flag on create.
     */
    private function importEvent(string $slug, bool $deleted): Event
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
            'is_deleted' => (int) $deleted,
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

    private function countBySlug(string $slug): int
    {
        return Event::withTrashed()->where('slug', $slug)->count();
    }
}
