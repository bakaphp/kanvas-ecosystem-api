<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Models\Insurer;
use Tests\TestCase;

class SeedInsuranceCatalogCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce'];

    public function testSeedsTheCatalogForTheGivenAppAndCompany(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->artisan('kanvas:insurance-seed-catalog', ['--app_id' => $app->getId(), '--company_id' => $company->getId()])
            ->assertSuccessful();

        $this->assertSame(
            2,
            Insurer::query()->fromApp($app)->fromCompany($company)->whereIn('slug', ['universal-seguros', 'humano'])->count()
        );
    }
}
