<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListSalesAppsTool;
use Tests\TestCase;
use Tests\Traits\BuildsSalesAppFixtures;

class ListSalesAppsToolTest extends TestCase
{
    use BuildsSalesAppFixtures;

    public function testListsOnlyTheCompanysActiveSalesApps(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $otherCompany = Companies::factory()->create();

        $shareVehicle = $this->makeSalesAppAction('Share Vehicle');
        $creditApp = $this->makeSalesAppAction('Credit App');
        $addTrade = $this->makeSalesAppAction('Add Trade');
        $deletedApp = $this->makeSalesAppAction('Deleted App');
        $otherCompanyApp = $this->makeSalesAppAction('Other Company App');

        $this->makeSalesApp(
            $creditApp,
            $company,
            isActive: true,
            weight: 2
        );
        $this->makeSalesApp($shareVehicle, $company, isActive: true);
        $this->makeSalesApp($shareVehicle, $company, isActive: true);
        $this->makeSalesApp($addTrade, $company, isActive: false);
        $this->makeSalesApp($deletedApp, $company, isActive: true)->softDelete();
        $this->makeSalesApp($otherCompanyApp, $otherCompany, isActive: true);

        $result = new ListSalesAppsTool()->withContext($app, $company, $user)->__invoke();

        $slugs = array_column($result['sales_apps'], 'slug');
        $ownActiveSlugs = array_values(array_intersect($slugs, [$shareVehicle->slug, $creditApp->slug]));

        $this->assertSame('success', $result['status']);
        $this->assertSame([$shareVehicle->slug, $creditApp->slug], $ownActiveSlugs);
        $this->assertCount(1, array_keys($slugs, $shareVehicle->slug, true));
        $this->assertNotContains($addTrade->slug, $slugs);
        $this->assertNotContains($deletedApp->slug, $slugs);
        $this->assertNotContains($otherCompanyApp->slug, $slugs);
        $this->assertContains([
            'slug' => $creditApp->slug,
            'name' => 'Credit App',
            'description' => 'Credit App description',
        ], $result['sales_apps']);
    }

    public function testRefusesWithoutTenantContext(): void
    {
        $result = new ListSalesAppsTool()->__invoke();

        $this->assertSame('no_tenant_context', $result['reason']);
    }
}
