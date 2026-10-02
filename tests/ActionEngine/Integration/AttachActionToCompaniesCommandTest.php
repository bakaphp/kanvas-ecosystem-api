<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\BuildsSalesAppFixtures;

/**
 * Serial: the command writes a global `apps_id = 0` actions row, which every paratest process shares.
 */
#[Group('serial')]
final class AttachActionToCompaniesCommandTest extends TestCase
{
    use BuildsSalesAppFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'crm', 'social'];

    private Apps $currentApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
    }

    public function testCreatesOneCompanyActionPerNonDeletedBranch(): void
    {
        $action = $this->makeSalesAppAction('Attach Fixture');
        $extraBranch = $this->makeBranch();
        $deletedBranch = $this->makeBranch(isDeleted: true);

        $this->runCommand($action->slug);

        $rows = $this->rowsFor($action);

        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertTrue($rows->pluck('companies_branches_id')->contains($extraBranch->getId()));
        $this->assertFalse($rows->pluck('companies_branches_id')->contains($deletedBranch->getId()));
        $this->assertTrue($rows->every(fn (CompanyAction $row): bool => (int) $row->is_active === 1));
    }

    public function testCreatesExactlyOnePipelineWithTheThreeStagesSharedByEveryBranchRow(): void
    {
        $action = $this->makeSalesAppAction('Attach Fixture Pipeline');
        $this->makeBranch();

        $this->runCommand($action->slug);

        $pipelines = Pipeline::query()
            ->where('slug', $action->slug)
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', $this->currentApp->getId())
            ->get();

        $this->assertCount(1, $pipelines);

        $stages = PipelineStage::where('pipelines_id', $pipelines->first()->getId())
            ->pluck('slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['opened', 'sent', 'submitted'], $stages);

        $pipelineIds = $this->rowsFor($action)->pluck('pipelines_id')->unique();
        $this->assertCount(1, $pipelineIds);
        $this->assertSame($pipelines->first()->getId(), (int) $pipelineIds->first());
    }

    public function testIsIdempotentAcrossReruns(): void
    {
        $action = $this->makeSalesAppAction('Attach Fixture Idempotent');
        $this->makeBranch();

        $this->runCommand($action->slug);

        $rows = $this->rowsFor($action)->count();
        $pipelines = Pipeline::where('slug', $action->slug)->count();
        $stages = PipelineStage::whereIn(
            'pipelines_id',
            Pipeline::where('slug', $action->slug)->pluck('id')
        )->count();

        $this->runCommand($action->slug);

        $this->assertSame($rows, $this->rowsFor($action)->count());
        $this->assertSame($pipelines, Pipeline::where('slug', $action->slug)->count());
        $this->assertSame($stages, PipelineStage::whereIn(
            'pipelines_id',
            Pipeline::where('slug', $action->slug)->pluck('id')
        )->count());
    }

    public function testDryRunWritesNothingAndExitsSuccess(): void
    {
        $action = $this->makeSalesAppAction('Attach Fixture Dry');

        $this->artisan('kanvas-action-engine:attach-action', [
            'app_id' => $this->currentApp->getId(),
            'slug' => $action->slug,
            '--company' => $this->company->getId(),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, $this->rowsFor($action)->count());
        $this->assertSame(0, Pipeline::where('slug', $action->slug)->count());
    }

    public function testInactiveOptionCreatesDisabledRows(): void
    {
        $action = $this->makeSalesAppAction('Attach Fixture Inactive');

        $this->artisan('kanvas-action-engine:attach-action', [
            'app_id' => $this->currentApp->getId(),
            'slug' => $action->slug,
            '--company' => $this->company->getId(),
            '--inactive' => true,
        ])->assertSuccessful();

        $this->assertTrue(
            $this->rowsFor($action)->every(fn (CompanyAction $row): bool => (int) $row->is_active === 0)
        );
    }

    public function testOnlyTargetsTheRequestedBranch(): void
    {
        $action = $this->makeSalesAppAction('Attach Fixture Branch');
        $branch = $this->makeBranch();
        $this->makeBranch();

        $this->artisan('kanvas-action-engine:attach-action', [
            'app_id' => $this->currentApp->getId(),
            'slug' => $action->slug,
            '--company' => $this->company->getId(),
            '--branch' => $branch->getId(),
        ])->assertSuccessful();

        $rows = $this->rowsFor($action);

        $this->assertCount(1, $rows);
        $this->assertSame($branch->getId(), (int) $rows->first()->companies_branches_id);
    }

    public function testFailsWhenSlugIsUnknownAndCreateActionIsAbsent(): void
    {
        $slug = 'never-seeded-' . Str::lower(Str::random(8));

        $this->artisan('kanvas-action-engine:attach-action', [
            'app_id' => $this->currentApp->getId(),
            'slug' => $slug,
        ])->assertFailed();

        $this->assertSame(0, Action::where('slug', $slug)->count());
    }

    public function testCreateActionMakesExactlyOneGlobalRow(): void
    {
        $slug = 'attach-fixture-' . Str::lower(Str::random(8));

        $this->artisan('kanvas-action-engine:attach-action', [
            'app_id' => $this->currentApp->getId(),
            'slug' => $slug,
            '--company' => $this->company->getId(),
            '--create-action' => true,
        ])->assertSuccessful();

        $actions = Action::where('slug', $slug)->where('is_deleted', 0)->get();

        $this->assertCount(1, $actions);
        $this->assertSame(0, (int) $actions->first()->companies_id);
        $this->assertSame(
            $actions->first()->getId(),
            Action::getBySlug($slug, $this->company)?->getId()
        );
    }

    /**
     * Regression for the phantom `companies_actions.status` column: it exists only in the legacy DB,
     * so any write touching it dies on a schema built purely from migrations.
     */
    public function testNeverWritesTheStatusColumn(): void
    {
        $action = $this->makeSalesAppAction('Attach Fixture Status');

        $this->runCommand($action->slug);

        $this->assertGreaterThan(0, $this->rowsFor($action)->count());
    }

    private function runCommand(string $slug): void
    {
        $this->artisan('kanvas-action-engine:attach-action', [
            'app_id' => $this->currentApp->getId(),
            'slug' => $slug,
            '--company' => $this->company->getId(),
        ])->assertSuccessful();
    }

    private function rowsFor(Action $action)
    {
        return CompanyAction::where('actions_id', $action->getId())
            ->where('apps_id', $this->currentApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->get();
    }

    private function makeBranch(bool $isDeleted = false): CompaniesBranches
    {
        // companies_id and users_id are $guarded on the model, so create() would drop them.
        $branch = new CompaniesBranches();
        $branch->companies_id = $this->company->getId();
        $branch->users_id = auth()->user()->getId();
        $branch->name = 'Branch ' . Str::random(6);
        $branch->is_default = 0;
        $branch->is_deleted = $isDeleted ? 1 : 0;
        $branch->saveOrFail();

        return $branch;
    }
}
