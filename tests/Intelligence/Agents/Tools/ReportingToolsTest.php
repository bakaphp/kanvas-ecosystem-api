<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetLeadAnalyticsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetSalesSummaryTool;
use Tests\TestCase;

class ReportingToolsTest extends TestCase
{
    public function testLeadAnalyticsCountsLeadsInRange(): void
    {
        $company = Companies::factory()->create();
        Lead::factory()->count(2)
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create();

        $result = new GetLeadAnalyticsTool()
            ->withContext(app(Apps::class), $company, auth()->user())('last_7_days');

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['total']);
        $this->assertArrayHasKey('by_source', $result);
        $this->assertArrayHasKey('by_salesperson', $result);
    }

    public function testSalesSummaryReturnsStructureWhenNoDeals(): void
    {
        $company = Companies::factory()->create();

        $result = new GetSalesSummaryTool()
            ->withContext(app(Apps::class), $company, auth()->user())('last_30_days');

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('by_stage', $result);
    }
}
