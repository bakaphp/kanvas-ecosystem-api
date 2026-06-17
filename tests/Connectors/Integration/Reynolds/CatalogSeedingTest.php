<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Handlers\ReynoldsHandler;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

/**
 * PM test case #2 — catalog seeding for lead status/source/type.
 *
 * Verifies that calling ReynoldsHandler::setup() seeds the catalogs the
 * connector relies on for name-based lookup:
 *   - LeadType: the four ProspectType values from the SalesAssist ISL spec
 *     (Internet / Phone / Other / List).
 *   - LeadStatus: Active / Sold / Lost / Closed so LeadObserver::closeSold()
 *     can resolve a real LeadStatus row regardless of what R&R sends back
 *     (Publish Lead Update payloads do not include ProspectStatus).
 *   - LeadSource: a default "Kanvas" provider used when PushLeadAction sends
 *     an Insert Sales Lead with no LeadSource attached to the originating
 *     Kanvas Lead.
 *
 * All seeds are idempotent on (apps_id, companies_id, name) so reinvoking
 * setup() during reconfiguration does not duplicate rows.
 */
final class CatalogSeedingTest extends TestCase
{
    public function testSetupSeedsAllFourProspectTypes(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = Regions::firstWhere('apps_id', $app->getId()) ?? Regions::first();

        $handler = new ReynoldsHandler($app, $company, $region, [
            'username' => 'test_user',
            'password' => 'test_pass',
            'endpoint' => 'https://b2b-test.example.invalid/Sync/RCI/SalesAssistCRM/Receive.ashx',
            'sender_name' => 'SalesAssist',
            'dealer_number' => 'TESTDEALER001',
            'store_number' => '02',
            'area_number' => '01',
            'business_unit_name' => 'Reynolds Test Dealership',
        ]);

        $handler->setup();

        foreach (['Internet', 'Phone', 'Other', 'List'] as $expectedName) {
            $row = LeadType::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('name', $expectedName)
                ->first();

            $this->assertNotNull(
                $row,
                "ReynoldsHandler::setup() must seed LeadType '{$expectedName}' (Reynolds ProspectType enum)."
            );
            $this->assertSame(1, (int) $row->is_active, "LeadType '{$expectedName}' must be active.");
        }
    }

    public function testSetupSeedsLeadStatuses(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = Regions::firstWhere('apps_id', $app->getId()) ?? Regions::first();

        new ReynoldsHandler($app, $company, $region, $this->minimalSetupData())->setup();

        foreach (['Active', 'Sold', 'Lost', 'Closed'] as $expectedName) {
            $row = LeadStatus::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('name', $expectedName)
                ->first();

            $this->assertNotNull($row, "ReynoldsHandler::setup() must seed LeadStatus '{$expectedName}'.");
        }

        // First seeded status should be marked default so any code path that
        // looks up "the default status for this company" lands on Active.
        $default = LeadStatus::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_default', 1)
            ->first();
        $this->assertNotNull($default);
        $this->assertSame('Active', $default->name);
    }

    public function testSetupSeedsKanvasLeadSource(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = Regions::firstWhere('apps_id', $app->getId()) ?? Regions::first();

        new ReynoldsHandler($app, $company, $region, $this->minimalSetupData())->setup();

        $source = LeadSource::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'Kanvas')
            ->first();

        $this->assertNotNull($source, "ReynoldsHandler::setup() must seed the default 'Kanvas' LeadSource.");
        $this->assertSame(1, (int) $source->is_active);
    }

    public function testSeedingIsIdempotentAcrossReinvocations(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = Regions::firstWhere('apps_id', $app->getId()) ?? Regions::first();

        new ReynoldsHandler($app, $company, $region, $this->minimalSetupData())->setup();
        $countAfterFirstSetup = LeadType::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereIn('name', ['Internet', 'Phone', 'Other', 'List'])
            ->count();

        new ReynoldsHandler($app, $company, $region, $this->minimalSetupData())->setup();
        $countAfterSecondSetup = LeadType::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereIn('name', ['Internet', 'Phone', 'Other', 'List'])
            ->count();

        $this->assertSame(
            $countAfterFirstSetup,
            $countAfterSecondSetup,
            'Reinvoking setup() must not duplicate seeded LeadType rows.'
        );
    }

    public function testCredentialsArePersistedOnCompanyNotApp(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = Regions::firstWhere('apps_id', $app->getId()) ?? Regions::first();

        new ReynoldsHandler($app, $company, $region, $this->minimalSetupData())->setup();

        $this->assertSame(
            'test_user',
            $company->get(ConfigurationEnum::REYNOLDS_USERNAME->value),
            'Username must be stored on the company (tenant-scoped), not the app.'
        );
        $this->assertSame(
            'TESTDEALER001',
            $company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value),
            'DealerNumber must be stored on the company.'
        );
    }

    private function minimalSetupData(): array
    {
        return [
            'username' => 'test_user',
            'password' => 'test_pass',
            'endpoint' => 'https://b2b-test.example.invalid/Sync/RCI/SalesAssistCRM/Receive.ashx',
            'sender_name' => 'SalesAssist',
            'dealer_number' => 'TESTDEALER001',
            'store_number' => '02',
            'area_number' => '01',
        ];
    }
}
