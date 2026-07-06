<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\LeadType;
use Tests\Connectors\Traits\HasVinsolutionConfiguration;
use Tests\TestCase;

final class SyncLeadTypesCommandTest extends TestCase
{
    use DatabaseTransactions;
    use HasVinsolutionConfiguration;

    public function testDownloadsLeadTypesFromSandbox(): void
    {
        $app = app(Apps::class);
        $this->getClient($app);
        $company = Companies::first();

        $this->artisan('kanvas:vinsolution-sync-lead-types', [
            'app_id' => $app->getId(),
            'company_ids' => (string) $company->getId(),
        ])
            ->expectsOutputToContain('Lead types synced:')
            ->assertSuccessful();

        $this->assertTrue(
            LeadType::fromApp($app)->fromCompany($company)->notDeleted()->exists()
        );
    }
}
