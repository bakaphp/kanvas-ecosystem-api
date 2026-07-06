<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Tests\Connectors\Traits\HasVinsolutionConfiguration;
use Tests\TestCase;

final class SyncLeadSourcesCommandTest extends TestCase
{
    use DatabaseTransactions;
    use HasVinsolutionConfiguration;

    public function testSkipsCompanyWithoutVinConfiguration(): void
    {
        $app = app(Apps::class);
        $company = Auth::user()->getCurrentCompany();

        if ($company->get(ConfigurationEnum::COMPANY->value)) {
            $this->markTestSkipped('Current company already has live VinSolution config.');
        }

        $this->artisan('kanvas:vinsolution-sync-lead-sources', [
            'app_id' => $app->getId(),
            'company_ids' => (string) $company->getId(),
        ])
            ->expectsOutputToContain('does not have VinSolution configuration')
            ->assertSuccessful();
    }

    public function testDownloadsLeadSourcesFromSandbox(): void
    {
        $app = app(Apps::class);
        $this->getClient($app);
        $company = Companies::first();

        $this->artisan('kanvas:vinsolution-sync-lead-sources', [
            'app_id' => $app->getId(),
            'company_ids' => (string) $company->getId(),
            '--from-first-page' => '1',
        ])
            ->expectsOutputToContain('Lead sources synced:')
            ->assertSuccessful();
    }
}
