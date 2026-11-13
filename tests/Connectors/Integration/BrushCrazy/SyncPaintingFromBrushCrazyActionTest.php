<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SyncPaintingFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\PaintingRelations;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class SyncPaintingFromBrushCrazyActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'event', 'ecosystem'];

    private int $bcPaintingId;

    protected function setUp(): void
    {
        parent::setUp();

        // Custom fields also land in Redis, which never rolls back — a fresh source id per test
        // keeps one run from resolving another run's Theme.
        $this->bcPaintingId = random_int(10_000_000, 99_999_999);
    }

    public function testCreatesTheThemeWithItsSheetAsCustomFields(): void
    {
        $theme = $this->action()->fromRow($this->row());

        $this->assertSame('#' . $this->bcPaintingId . ' - Starry Night', $theme->name);
        $this->assertSame('2021-03-01', $theme->created_at->format('Y-m-d'));
        $this->assertSame('After Van Gogh', $theme->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_SUBTITLE->value));
        $this->assertSame('A night sky.', $theme->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_DESCRIPTION->value));
        $this->assertCount(1, $theme->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_DIRECTIONS->value));
        $this->assertSame(['landscape'], $theme->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_TAGS->value)['category']);
        $this->assertSame('11x14 Canvas', $theme->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_RECIPE->value)['substrates'][0]['name']);
    }

    /** The calendarable sync resolves theme_id through exactly this map. */
    public function testThemeIsResolvableByPaintingIdTheWayCalendarablesLookItUp(): void
    {
        $theme = $this->action()->fromRow($this->row());

        $map = new IdMapRepository()->load(
            (int) auth()->user()->getCurrentCompany()->getId(),
            Theme::class,
            CustomFieldEnum::BRUSHCRAZY_PAINTING_ID,
        );

        $this->assertSame($theme->getId(), $map[(string) $this->bcPaintingId]);
    }

    public function testReSyncUpdatesTheSameRowAndClearsRemovedValues(): void
    {
        $first = $this->action()->fromRow($this->row());
        $second = $this->action()->fromRow($this->row(['title' => 'Starry Night II', 'subtitle' => null]));

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame('#' . $this->bcPaintingId . ' - Starry Night II', $second->fresh()->name);
        $this->assertNull($second->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_SUBTITLE->value));
    }

    public function testSoftDeletedPaintingIsFlaggedAndFoundAgainOnTheNextRun(): void
    {
        $deleted = $this->action()->fromRow($this->row(['deleted_at' => '2025-01-01 00:00:00']));
        $again = $this->action()->fromRow($this->row(['deleted_at' => '2025-01-01 00:00:00']));

        $this->assertTrue((bool) Theme::withTrashed()->find($deleted->getId())->is_deleted);
        $this->assertSame($deleted->getId(), $again->getId());
    }

    /** Resolution is by BRUSHCRAZY_PAINTING_ID only; a native Theme with the same name is not BrushCrazy's. */
    public function testDoesNotAdoptANativeThemeWithTheSameName(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $native = Theme::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => '#' . $this->bcPaintingId . ' - Starry Night',
        ]);

        $synced = $this->action()->fromRow($this->row());

        $this->assertNotSame($native->getId(), $synced->getId());
    }

    /** Deleted in Kanvas by a user, not by the mirror: a re-sync must not bring it back. */
    public function testAThemeDeletedInKanvasIsNotResurrected(): void
    {
        $theme = $this->action()->fromRow($this->row());
        Theme::withTrashed()->whereKey($theme->getId())->update(['is_deleted' => 1]);

        $this->action()->fromRow($this->row(['title' => 'Starry Night II']));

        $this->assertTrue((bool) Theme::withTrashed()->find($theme->getId())->is_deleted);
    }

    public function testASourceDeleteIsUndoneByASourceRestore(): void
    {
        $theme = $this->action()->fromRow($this->row(['deleted_at' => '2025-01-01 00:00:00']));
        $this->assertTrue((bool) Theme::withTrashed()->find($theme->getId())->is_deleted);

        $restored = $this->action()->fromRow($this->row());

        $this->assertSame($theme->getId(), $restored->getId());
        $this->assertFalse((bool) Theme::withTrashed()->find($theme->getId())->is_deleted);
    }

    /**
     * The real relations source is built only when none is injected, which every other test does;
     * this one takes that branch so a broken constructor call cannot hide behind the fake.
     */
    public function testWithoutInjectedRelationsTheyAreReadFromTheBrushCrazyDatabase(): void
    {
        if (Client::isConfigured(app(Apps::class))) {
            $this->markTestSkipped('A BrushCrazy database is configured for this app.');
        }

        $this->expectException(ValidationException::class);

        $company = auth()->user()->getCurrentCompany();

        new SyncPaintingFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            new StudioContext(
                bcStudioId: 2,
                company: $company,
                branch: $company->defaultBranch,
                themeArea: new ThemeArea(),
                timezone: 'America/New_York',
            ),
        );
    }

    /** The skip-unchanged path of a preloaded chunk must still leave every value in place. */
    public function testAnUnchangedPreloadedReSyncKeepsTheValues(): void
    {
        $theme = $this->action()->fromRow($this->row());
        $action = $this->action();
        $action->preload([$this->bcPaintingId]);

        $action->fromRow($this->row());

        $this->assertSame('After Van Gogh', $theme->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_SUBTITLE->value));
        $this->assertSame($this->bcPaintingId, (int) $theme->get(CustomFieldEnum::BRUSHCRAZY_PAINTING_ID->value));
    }

    private function action(): SyncPaintingFromBrushCrazyAction
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new SyncPaintingFromBrushCrazyAction(
            app(Apps::class),
            $user,
            new StudioContext(
                bcStudioId: 2,
                company: $company,
                branch: $company->defaultBranch,
                themeArea: new ThemeArea(),
                timezone: 'America/New_York',
            ),
            $this->relations(),
        );
    }

    /**
     * Stands in for the BrushCrazy database. No media rows: photo attachment needs the
     * BRUSHCRAZY_MEDIA_BASE_URL app setting, which is shared state across parallel tests; the URL
     * and dedupe shape are covered by PaintingMapperTest.
     */
    private function relations(): PaintingRelations
    {
        return new class () extends PaintingRelations {
            public function __construct()
            {
            }

            public function forPaintings(array $paintingIds): array
            {
                $related = [];

                foreach ($paintingIds as $paintingId) {
                    $related[(int) $paintingId] = [
                        'directions' => [
                            (object) ['id' => 1, 'painting_id' => $paintingId, 'directions' => 'Base coat', 'sort_order' => 1],
                            (object) [
                                'id' => 2,
                                'painting_id' => $paintingId,
                                'directions' => 'A night sky.',
                                'sort_order' => PaintingMapper::DESCRIPTION_SORT_ORDER,
                            ],
                        ],
                        'tags' => ['category:landscape', 'staff-pick'],
                        'recipe' => ['substrates' => [(object) ['painting_id' => $paintingId, 'id' => 5, 'name' => '11x14 Canvas', 'sort_order' => 1]]],
                        'media' => [],
                    ];
                }

                return $related;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => $this->bcPaintingId,
            'title' => 'Starry Night',
            'subtitle' => 'After Van Gogh',
            'difficulty' => 'Beginner',
            'duration' => '2 hours',
            'published_at' => '2021-03-02 09:00:00',
            'approved_at' => '2021-03-01 12:00:00',
            'created_at' => '2021-03-01 10:00:00',
            'deleted_at' => null,
        ]);
    }
}
