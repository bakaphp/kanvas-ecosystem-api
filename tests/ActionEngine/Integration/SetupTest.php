<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\CreatesBareApps;

final class SetupTest extends TestCase
{
    use CreatesBareApps;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'social'];

    public function testSetupWithNoActionsCreatesDefaults(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $this->makeCompany();

        new Setup($app, $user, $company)->run();

        $companyActions = CompanyAction::where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->get();

        $slugs = $companyActions->map(fn (CompanyAction $ca) => $ca->action->slug)->all();

        $this->assertCount(3, $companyActions, 'A fresh company should get exactly the 3 defaults.');
        $this->assertContains(ActionEnum::VIEW_PRODUCT->value, $slugs);
        $this->assertContains(ActionEnum::GET_DOCS->value, $slugs);
        $this->assertContains(ActionEnum::SYNC->value, $slugs);

        foreach ($companyActions as $companyAction) {
            $this->assertGreaterThan(0, $companyAction->pipelines_id, 'Each default action should have a pipeline.');
            $this->assertGreaterThan(
                0,
                $companyAction->pipeline->stages()->count(),
                'Each default pipeline should have stages.'
            );
        }
    }

    public function testSetupClonesConfigFromAnotherCompany(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $source = $this->makeCompany();
        new Setup($app, $user, $source)->run();

        // Mutate the source get-docs action so we can prove the clone copies config.
        $sourceGetDocs = CompanyAction::where('companies_id', $source->getId())
            ->whereHas('action', fn ($q) => $q->where('slug', ActionEnum::GET_DOCS->value))
            ->where('is_deleted', 0)
            ->firstOrFail();
        $sourceGetDocs->name = 'Custom Docs Name';
        $sourceGetDocs->is_active = 1;
        $sourceGetDocs->saveOrFail();

        $target = $this->makeCompany();
        new Setup($app, $user, $target, [], $source)->run();

        $targetActions = CompanyAction::where('companies_id', $target->getId())
            ->where('is_deleted', 0)
            ->get();

        $targetSlugs = $targetActions->map(fn (CompanyAction $ca) => $ca->action->slug)->all();

        $this->assertCount(3, $targetActions, 'The target should mirror the source action count.');
        $this->assertContains(ActionEnum::VIEW_PRODUCT->value, $targetSlugs);
        $this->assertContains(ActionEnum::GET_DOCS->value, $targetSlugs);
        $this->assertContains(ActionEnum::SYNC->value, $targetSlugs);

        $targetGetDocs = $targetActions->first(
            fn (CompanyAction $ca) => $ca->action->slug === ActionEnum::GET_DOCS->value
        );
        $this->assertSame('Custom Docs Name', $targetGetDocs->name, 'Cloned action should carry the source name.');
        $this->assertSame(1, (int) $targetGetDocs->is_active, 'Cloned action should carry the source active state.');

        foreach ($targetActions as $companyAction) {
            $this->assertGreaterThan(0, $companyAction->pipelines_id, 'Each cloned action should have a pipeline.');
            $this->assertGreaterThan(
                0,
                $companyAction->pipeline->stages()->count(),
                'Each cloned pipeline should have stages.'
            );
        }
    }

    /**
     * companies_actions is per (app, company). The firstOrCreate lookups must key on apps_id, or a
     * company already set up under one app satisfies the match for every other app and the second
     * app silently ends up with no rows.
     */
    public function testSetupUnderASecondAppCreatesThatAppsOwnCompanyActions(): void
    {
        $app = app(Apps::class);
        $otherApp = $this->createBareApp();
        $user = auth()->user();
        $company = $this->makeCompany();

        new Setup($app, $user, $company)->run();
        new Setup($otherApp, $user, $company)->run();

        $this->assertCount(
            3,
            $this->companyActionsFor($company, $otherApp),
            'The second app must get its own companies_actions rows.'
        );
        $this->assertCount(
            3,
            $this->companyActionsFor($company, $app),
            'Setting up a second app must leave the first app\'s rows untouched.'
        );
    }

    private function companyActionsFor(Companies $company, Apps $app): Collection
    {
        return CompanyAction::where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->get();
    }

    private function makeCompany(): Companies
    {
        /** @var Users $user */
        $user = auth()->user();

        return new CreateCompaniesAction(
            Company::viaRequest(['name' => fake()->unique()->company], $user)
        )->execute();
    }
}
