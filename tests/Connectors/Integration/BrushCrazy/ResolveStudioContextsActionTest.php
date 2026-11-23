<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Actions\ResolveStudioContextsAction;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Users\Models\UserCompanyApps;
use stdClass;
use Tests\TestCase;

final class ResolveStudioContextsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'event', 'ecosystem'];

    /**
     * Regression: studio 0 was first named "Studio 0" and later "Corporate"; looking the area up by
     * name created a second ThemeArea carrying the same BRUSHCRAZY_STUDIO_ID.
     */
    public function testARenamedStudioKeepsItsThemeAreaAndReplacesTheFallbackName(): void
    {
        $app = app(Apps::class);
        $bcStudioId = random_int(900_000, 999_999);
        $company = $this->studioCompany($bcStudioId, 'Studio ' . $bcStudioId);

        $area = ThemeArea::firstOrCreate(
            [
                'name' => 'Studio ' . $bcStudioId,
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
            ],
            ['users_id' => auth()->user()->getId()],
        );
        $area->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);

        $context = $this->resolve($this->studio($bcStudioId, 'Corporate'));

        $this->assertSame($company->getId(), $context->companyId());
        $this->assertSame($area->getId(), $context->themeArea->getId());
        $this->assertSame('Corporate', $context->themeArea->fresh()->name);
        $this->assertSame('Corporate', $company->fresh()->name);
        $this->assertSame(
            1,
            ThemeArea::query()->where('companies_id', $company->getId())->whereIn('name', ['Corporate', 'Studio ' . $bcStudioId])->count()
        );
    }

    public function testANameChosenInKanvasIsNotOverwritten(): void
    {
        $bcStudioId = random_int(900_000, 999_999);
        $company = $this->studioCompany($bcStudioId, 'Head Office');

        $this->resolve($this->studio($bcStudioId, 'Corporate'));

        $this->assertSame('Head Office', $company->fresh()->name);
    }

    public function testTheCompanyLookupIsScopedToTheApp(): void
    {
        $app = app(Apps::class);
        $bcStudioId = random_int(900_000, 999_999);
        $company = $this->studioCompany($bcStudioId, 'Studio ' . $bcStudioId);

        $this->assertSame($company->getId(), ResolveStudioContextsAction::companyForStudio($app, $bcStudioId)?->getId());

        UserCompanyApps::query()
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->update(['is_deleted' => 1]);

        $this->assertNull(ResolveStudioContextsAction::companyForStudio($app, $bcStudioId));
    }

    /**
     * Regression: every studio was resolved (companies created, settings and names rewritten)
     * before --studio filtered, and an unknown id threw, failing the app on every scheduled tick.
     */
    public function testOnlyTheRequestedStudiosAreResolvedAndUnknownIdsAreReported(): void
    {
        $requested = random_int(900_000, 949_999);
        $untouched = random_int(950_000, 999_999);
        $unknown = 1_000_000 + random_int(1, 99_999);
        $this->studioCompany($requested, 'Studio ' . $requested);
        $other = $this->studioCompany($untouched, 'Studio ' . $untouched);

        $action = $this->listingAction(
            [$this->studio($requested, 'Corporate'), $this->studio($untouched, 'Denver')],
            onlyStudioIds: [$requested, $unknown],
        );
        $contexts = $action->execute();

        $this->assertSame([$requested], array_keys($contexts));
        $this->assertSame([$unknown], $action->unknownStudioIds);
        $this->assertSame('Studio ' . $untouched, $other->fresh()->name);
    }

    public function testAPausedStudioIsLeftUntouchedWhenAskedToSkipIt(): void
    {
        $paused = random_int(900_000, 949_999);
        $unflagged = random_int(950_000, 999_999);
        $pausedCompany = $this->studioCompany($paused, 'Studio ' . $paused);
        $pausedCompany->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, false);
        $this->studioCompany($unflagged, 'Studio ' . $unflagged);

        try {
            $action = $this->listingAction(
                [$this->studio($paused, 'Corporate'), $this->studio($unflagged, 'Denver')],
                skipPausedStudios: true,
            );
            $contexts = $action->execute();

            $this->assertSame([$unflagged], array_keys($contexts));
            $this->assertSame([$paused], $action->pausedStudioIds);
            $this->assertSame('Studio ' . $paused, $pausedCompany->fresh()->name);
        } finally {
            $pausedCompany->del(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value);
        }
    }

    public function testOnlyAnExplicitOffCountsAsPaused(): void
    {
        $company = Companies::factory()->create();

        try {
            $this->assertFalse(ResolveStudioContextsAction::isPaused(null));
            $this->assertFalse(ResolveStudioContextsAction::isPaused($company));

            $company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);
            $this->assertFalse(ResolveStudioContextsAction::isPaused($company));

            $company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, false);
            $this->assertTrue(ResolveStudioContextsAction::isPaused($company));
        } finally {
            $company->del(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value);
        }
    }

    /**
     * The source rows stand in for the BrushCrazy `studios` table, filtered the way the real
     * query's whereIn would.
     *
     * @param  array<int, stdClass>  $rows
     * @param  array<int, int>|null  $onlyStudioIds
     */
    private function listingAction(
        array $rows,
        ?array $onlyStudioIds = null,
        bool $skipPausedStudios = false
    ): ResolveStudioContextsAction {
        return new class ($rows, $onlyStudioIds, $skipPausedStudios) extends ResolveStudioContextsAction {
            /**
             * @param  array<int, stdClass>  $rows
             * @param  array<int, int>|null  $onlyStudioIds
             */
            public function __construct(
                private array $rows,
                ?array $onlyStudioIds,
                bool $skipPausedStudios
            ) {
                parent::__construct(
                    app(Apps::class),
                    auth()->user(),
                    onlyStudioIds: $onlyStudioIds,
                    skipPausedStudios: $skipPausedStudios,
                );
            }

            protected function studioRows(): Collection
            {
                return collect($this->rows)
                    ->filter(fn (stdClass $row) => $this->onlyStudioIds === null || in_array((int) $row->id, $this->onlyStudioIds, true))
                    ->values();
            }
        };
    }

    private function studioCompany(int $bcStudioId, string $name): Companies
    {
        $company = Companies::factory()->create(['name' => $name]);
        $company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId);

        return $company;
    }

    private function studio(int $bcStudioId, string $state): stdClass
    {
        return (object) [
            'id' => $bcStudioId,
            'community' => null,
            'locality' => null,
            'administrative_area_level_1' => $state,
            'timezone' => 'America/Denver',
        ];
    }

    private function resolve(stdClass $studio): StudioContext
    {
        $action = new class (app(Apps::class), auth()->user()) extends ResolveStudioContextsAction {
            public function resolve(stdClass $studio): StudioContext
            {
                return $this->resolveStudio($studio, 'America/Denver');
            }
        };

        return $action->resolve($studio);
    }
}
