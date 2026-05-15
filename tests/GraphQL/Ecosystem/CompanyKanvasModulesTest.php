<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\KanvasModules\Actions\RefreshCompanyKanvasModulesSummaryAction;
use Kanvas\KanvasModules\Enums\CompanyKanvasModuleStatusEnum;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\KanvasModules\Models\CompanyKanvasModule;
use Tests\TestCase;

class CompanyKanvasModulesTest extends TestCase
{
    private function resetModulesForCurrentCompany(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        CompanyKanvasModule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->delete();
    }

    public function testCompanyKanvasModulesReturnsGrantedModules(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $this->resetModulesForCurrentCompany();
        $company->grantModule(KanvasModuleEnum::CRM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::INVENTORY, $app, CompanyKanvasModuleStatusEnum::NOT_CONNECTED);

        $this->graphQL('
            query {
                companyKanvasModules(first: 50) {
                    data {
                        id
                        is_active
                        status
                        module {
                            id
                            name
                            is_internal
                            is_default
                        }
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ')
            ->assertSuccessful()
            ->assertJsonPath('data.companyKanvasModules.paginatorInfo.total', 2);
    }

    public function testCompanyKanvasModulesSummaryAggregatesByStatus(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $this->resetModulesForCurrentCompany();
        $company->grantModule(KanvasModuleEnum::CRM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::INVENTORY, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::SOCIAL, $app, CompanyKanvasModuleStatusEnum::PARTIAL);
        $company->grantModule(KanvasModuleEnum::AI, $app, CompanyKanvasModuleStatusEnum::NOT_CONNECTED);

        $this->graphQL('
            query {
                companyKanvasModulesSummary {
                    connected
                    partial
                    not_connected
                    total
                }
            }
        ')
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'companyKanvasModulesSummary' => [
                        'connected' => 2,
                        'partial' => 1,
                        'not_connected' => 1,
                        'total' => 4,
                    ],
                ],
            ]);
    }

    public function testCompanyKanvasModulesSummaryExcludesInternalModules(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $this->resetModulesForCurrentCompany();
        $company->grantModule(KanvasModuleEnum::CRM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::ECOSYSTEM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::WORKFLOW, $app, CompanyKanvasModuleStatusEnum::CONNECTED);

        $this->graphQL('
            query {
                companyKanvasModulesSummary {
                    connected
                    total
                }
            }
        ')
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'companyKanvasModulesSummary' => [
                        'connected' => 1,
                        'total' => 1,
                    ],
                ],
            ]);
    }

    public function testRefreshSummaryActionPopulatesPerModulePayload(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $this->resetModulesForCurrentCompany();
        $company->grantModule(KanvasModuleEnum::CRM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::INVENTORY, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::SOCIAL, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::ECOSYSTEM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);

        $result = new RefreshCompanyKanvasModulesSummaryAction($company, $app)->execute();

        $this->assertSame(3, $result['refreshed']);
        $this->assertEqualsCanonicalizing(
            [KanvasModuleEnum::CRM->value, KanvasModuleEnum::INVENTORY->value, KanvasModuleEnum::SOCIAL->value],
            $result['module_ids'],
        );

        $crm = CompanyKanvasModule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::CRM->value)
            ->firstOrFail();
        $this->assertIsArray($crm->summary);
        $this->assertArrayHasKey('leads', $crm->summary);
        $this->assertArrayHasKey('people', $crm->summary);

        $inventory = CompanyKanvasModule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::INVENTORY->value)
            ->firstOrFail();
        $this->assertIsArray($inventory->summary);
        $this->assertArrayHasKey('products', $inventory->summary);

        $ecosystem = CompanyKanvasModule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::ECOSYSTEM->value)
            ->firstOrFail();
        $this->assertNull($ecosystem->summary);
    }

    public function testCompanyKanvasModulesGraphQLExposesSummary(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $this->resetModulesForCurrentCompany();
        $company->grantModule(KanvasModuleEnum::CRM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        new RefreshCompanyKanvasModulesSummaryAction($company, $app)->execute();

        $response = $this->graphQL('
            query {
                companyKanvasModules(first: 50) {
                    data {
                        status
                        summary
                        module { id name }
                    }
                }
            }
        ')->assertSuccessful();

        $rows = $response->json('data.companyKanvasModules.data');
        $this->assertNotEmpty($rows);
        $crmRow = collect($rows)->firstWhere('module.id', (string) KanvasModuleEnum::CRM->value);
        $this->assertNotNull($crmRow);
        $this->assertIsArray($crmRow['summary']);
        $this->assertArrayHasKey('leads', $crmRow['summary']);
        $this->assertArrayHasKey('people', $crmRow['summary']);
    }

    public function testCompanyKanvasModulesIgnoresInactiveAndDeletedRows(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $this->resetModulesForCurrentCompany();
        $company->grantModule(KanvasModuleEnum::CRM, $app, CompanyKanvasModuleStatusEnum::CONNECTED);
        $company->grantModule(KanvasModuleEnum::INVENTORY, $app, CompanyKanvasModuleStatusEnum::CONNECTED);

        CompanyKanvasModule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::INVENTORY->value)
            ->update(['is_active' => false]);

        $this->graphQL('
            query {
                companyKanvasModulesSummary {
                    connected
                    total
                }
            }
        ')
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'companyKanvasModulesSummary' => [
                        'connected' => 1,
                        'total' => 1,
                    ],
                ],
            ]);
    }
}
