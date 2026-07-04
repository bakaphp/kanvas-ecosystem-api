<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Tests\TestCase;

final class DownloadAllLeadsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function testAutoDiscoversOptedInCompaniesWhenNoCompanyIdProvided(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        // A live VinSolution config would make the command hit the real API; skip
        // so this stays a pure discovery-wiring test with no external dependency.
        if ($company->get(ConfigurationEnum::COMPANY->value)) {
            $this->markTestSkipped('Current company already has live VinSolution config.');
        }

        $company->set(ConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value, (string) $user->getId());

        try {
            $this->artisan('kanvas:vinsolution-download-all-leads', ['app_id' => $app->getId()])
                ->expectsOutputToContain('Auto-discovered')
                ->expectsOutputToContain('Company does not have VinSolution configuration')
                ->assertSuccessful();
        } finally {
            $company->del(ConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);
        }
    }

    public function testDoesNothingWhenNoCompanyOptedIn(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $company->del(ConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);

        $this->artisan('kanvas:vinsolution-download-all-leads', ['app_id' => $app->getId()])
            ->expectsOutputToContain('No companies opted into the VinSolution bulk lead download')
            ->assertSuccessful();
    }
}
