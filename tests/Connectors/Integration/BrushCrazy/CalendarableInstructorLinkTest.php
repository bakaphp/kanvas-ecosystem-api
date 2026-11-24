<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SeedBrushCrazyLookupDataAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCalendarableFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncStaffFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Facilitators\Models\EventVersionFacilitator;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Event\Themes\Models\ThemeArea;
use Tests\TestCase;

final class CalendarableInstructorLinkTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'event', 'ecosystem'];

    private StudioContext $context;

    private int $lessonId;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new SeedBrushCrazyLookupDataAction($app, $company, $user)->execute();

        $this->context = new StudioContext(
            bcStudioId: 2,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: ThemeArea::where('companies_id', $company->getId())
                ->where('apps_id', $app->getId())
                ->where('name', SeedBrushCrazyLookupDataAction::UNASSIGNED)
                ->firstOrFail(),
            timezone: 'America/Denver',
        );

        // Source ids end up in custom fields, and Redis never rolls back.
        $this->lessonId = random_int(10_000_000, 99_999_999);
    }

    public function testLinksTheAssigneeAsTheVersionFacilitator(): void
    {
        $instructor = $this->staff();

        $version = $this->calendarables()->fromRow(CalendarableTypeEnum::LESSON, $this->lesson($instructor['bcUserId']));

        $this->assertSame([(int) $instructor['facilitator']->getId()], $this->activeFacilitatorIds($version));
    }

    public function testReassignmentSoftDeletesTheOldLinkAndReassigningBackRestoresIt(): void
    {
        $first = $this->staff();
        $second = $this->staff();
        $sync = $this->calendarables();

        $version = $sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson($first['bcUserId']));
        $sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson($second['bcUserId']));

        $this->assertSame([(int) $second['facilitator']->getId()], $this->activeFacilitatorIds($version));

        $sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson($first['bcUserId']));

        $this->assertSame([(int) $first['facilitator']->getId()], $this->activeFacilitatorIds($version));
        $this->assertSame(2, EventVersionFacilitator::withTrashed()->where('event_version_id', $version->getId())->count());
    }

    public function testUnassigningSoftDeletesTheLink(): void
    {
        $instructor = $this->staff();
        $sync = $this->calendarables();

        $version = $sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson($instructor['bcUserId']));
        $sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson(null));

        $this->assertSame([], $this->activeFacilitatorIds($version));
    }

    /** A link a user added in Kanvas points at a Facilitator the mirror does not own. */
    public function testLeavesALinkToANativeFacilitatorAlone(): void
    {
        $instructor = $this->staff();
        $sync = $this->calendarables();
        $version = $sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson($instructor['bcUserId']));

        $native = Facilitator::withoutEvents(fn () => Facilitator::create([
            'uuid' => Str::uuid()->toString(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->context->companyId(),
            'users_id' => auth()->user()->getId(),
            'people_id' => $instructor['facilitator']->people_id,
            'slug' => 'native-' . $this->lessonId,
        ]));
        EventVersionFacilitator::create([
            'event_version_id' => $version->getId(),
            'facilitator_id' => $native->getId(),
        ]);

        $sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson(null));

        $this->assertSame([(int) $native->getId()], $this->activeFacilitatorIds($version));
    }

    public function testAnAssigneeWithNoFacilitatorIsSkipped(): void
    {
        $version = $this->calendarables()->fromRow(CalendarableTypeEnum::LESSON, $this->lesson(random_int(10_000_000, 99_999_999)));

        $this->assertSame([], $this->activeFacilitatorIds($version));
    }

    /**
     * @return array{bcUserId: int, facilitator: Facilitator}
     */
    private function staff(): array
    {
        $bcUserId = random_int(10_000_000, 99_999_999);

        $facilitator = new SyncStaffFromBrushCrazyAction(
            app: app(Apps::class),
            user: auth()->user(),
            context: $this->context,
            credits: new FakeStudioCredits(),
        )->fromRow(
            (object) [
                'id' => $bcUserId,
                'name' => 'Instructor ' . $bcUserId,
                'email' => $bcUserId . '@example.com',
                'deleted_at' => null,
            ],
            [
                (object) [
                    'id' => 1,
                    'employee_id' => 1000,
                    'position' => 'Artist',
                    'wage' => null,
                    'hired_at' => '2021-01-01 00:00:00',
                    'terminated_at' => null,
                    'transitioned_at' => null,
                ],
            ],
            false
        );

        return ['bcUserId' => $bcUserId, 'facilitator' => $facilitator];
    }

    private function calendarables(): SyncCalendarableFromBrushCrazyAction
    {
        return new SyncCalendarableFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context,
            Carbon::now('UTC'),
        );
    }

    private function lesson(?int $assigneeId): object
    {
        return (object) [
            'id' => $this->lessonId,
            'studio_id' => 2,
            'name' => 'Starry Night',
            'description' => null,
            'type' => null,
            'start' => '2025-03-01 01:00:00',
            'end' => '2025-03-01 03:00:00',
            'occupancy' => 20,
            'painting_id' => null,
            'assignee_id' => $assigneeId,
            'published_at' => '2025-02-01 00:00:00',
            'rsvp' => 0,
            'private' => 0,
            'fundraiser' => 0,
            'password' => null,
            'deleted_at' => null,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function activeFacilitatorIds(EventVersion $version): array
    {
        return EventVersionFacilitator::where('event_version_id', $version->getId())
            ->pluck('facilitator_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
