<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

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

final class SetupTest extends TestCase
{
    use DatabaseTransactions;

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

        $this->assertCount(2, $companyActions, 'A fresh company should get exactly the 2 defaults.');
        $this->assertContains(ActionEnum::VIEW_PRODUCT->value, $slugs);
        $this->assertContains(ActionEnum::GET_DOCS->value, $slugs);

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

        $this->assertCount(2, $targetActions, 'The target should mirror the source action count.');
        $this->assertContains(ActionEnum::VIEW_PRODUCT->value, $targetSlugs);
        $this->assertContains(ActionEnum::GET_DOCS->value, $targetSlugs);

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

    private function makeCompany(): Companies
    {
        /** @var Users $user */
        $user = auth()->user();

        return new CreateCompaniesAction(
            Company::viaRequest(['name' => fake()->unique()->company], $user)
        )->execute();
    }
}
