<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\SyncEntityEnum;
use Kanvas\Connectors\Acumatica\Jobs\SyncAcumaticaCompanyJob;
use Kanvas\Connectors\Acumatica\Services\AcumaticaSyncService;
use Tests\TestCase;

class AcumaticaSyncTest extends TestCase
{
    use DatabaseTransactions;

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    private function enableCompany(Companies $company, int $acumaticaCompanyId = 2): void
    {
        // SqlClient::isConfigured() only checks sqlHost + sqlDatabase.
        $this->app()->set(ConfigurationEnum::ACUMATICA_CONFIG->value, [
            'sqlHost' => 'acumatica.test',
            'sqlDatabase' => 'Replica',
        ]);

        $company->set(ConfigurationEnum::SYNC_ENABLED->value, '1');
        $company->set(ConfigurationEnum::SYNC_APP_ID->value, (string) $this->app()->getId());
        $company->set(ConfigurationEnum::SYNC_COMPANY_ID->value, (string) $acumaticaCompanyId);
        $company->set(ConfigurationEnum::SYNC_USER_ID->value, (string) auth()->user()->getId());
    }

    public function testCursorRoundTrips(): void
    {
        $sync = new AcumaticaSyncService();
        $company = $this->company();

        $this->assertNull($sync->cursorFor($company, SyncEntityEnum::INVOICES));

        $ts = Carbon::parse('2026-03-01 12:00:00');
        $sync->advanceCursor($company, SyncEntityEnum::INVOICES, $ts);

        $got = $sync->cursorFor($company, SyncEntityEnum::INVOICES);
        $this->assertNotNull($got);
        $this->assertSame($ts->toIso8601String(), $got->toIso8601String());
    }

    public function testCursorsAreIndependentPerEntity(): void
    {
        $sync = new AcumaticaSyncService();
        $company = $this->company();

        $sync->advanceCursor($company, SyncEntityEnum::BILLS, Carbon::parse('2026-02-01 00:00:00'));

        $this->assertNotNull($sync->cursorFor($company, SyncEntityEnum::BILLS));
        $this->assertNull($sync->cursorFor($company, SyncEntityEnum::ORDERS));
    }

    public function testResolveTargetReturnsNullWhenNotEnabled(): void
    {
        $sync = new AcumaticaSyncService();
        $company = $this->company();

        $this->assertNull($sync->resolveTarget($company), 'No setting → not runnable.');

        $company->set(ConfigurationEnum::SYNC_ENABLED->value, '0');
        $this->assertNull($sync->resolveTarget($company), 'Explicitly disabled → not runnable.');
    }

    public function testResolveTargetReturnsNullWhenEnabledButUnconfigured(): void
    {
        $sync = new AcumaticaSyncService();
        $company = $this->company();

        // Enabled but no app-id / acumatica-company-id set.
        $company->set(ConfigurationEnum::SYNC_ENABLED->value, '1');

        $this->assertNull($sync->resolveTarget($company));
    }

    public function testResolveTargetBuildsTargetWhenFullyConfigured(): void
    {
        $sync = new AcumaticaSyncService();
        $company = $this->company();
        $this->enableCompany($company, acumaticaCompanyId: 7);

        $target = $sync->resolveTarget($company);

        $this->assertNotNull($target);
        $this->assertSame($this->app()->getId(), $target->app->getId());
        $this->assertSame($company->getId(), $target->company->getId());
        $this->assertSame(7, $target->acumaticaCompanyId);
    }

    public function testEnabledCompaniesReflectsTheToggle(): void
    {
        $sync = new AcumaticaSyncService();
        $company = $this->company();

        $this->enableCompany($company);
        $enabledIds = $sync->enabledCompanies()->map(fn (Companies $c): int => $c->getId())->all();
        $this->assertContains($company->getId(), $enabledIds);

        $company->set(ConfigurationEnum::SYNC_ENABLED->value, '0');
        $enabledIds = $sync->enabledCompanies()->map(fn (Companies $c): int => $c->getId())->all();
        $this->assertNotContains($company->getId(), $enabledIds);
    }

    public function testScheduledCommandDispatchesOnlyForEnabledCompany(): void
    {
        Bus::fake();
        $this->enableCompany($this->company());

        $this->artisan('kanvas:acumatica-sync')->assertSuccessful();

        Bus::assertDispatched(SyncAcumaticaCompanyJob::class);
    }

    public function testScheduledCommandDispatchesNothingWhenDisabled(): void
    {
        Bus::fake();
        $this->company()->set(ConfigurationEnum::SYNC_ENABLED->value, '0');

        $this->artisan('kanvas:acumatica-sync')->assertSuccessful();

        Bus::assertNotDispatched(SyncAcumaticaCompanyJob::class);
    }
}
