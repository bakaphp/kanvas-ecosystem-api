<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetDealAnalyticsTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class GetDealAnalyticsToolTest extends TestCase
{
    private function tool(): GetDealAnalyticsTool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return new GetDealAnalyticsTool()->withContext($app, $user->getCurrentCompany(), $user);
    }

    public function testReturnsStructuredDealBreakdownOverTheTimeframe(): void
    {
        // Exercises the full BuildAnalyticsAction path over Deal — if the group-by relations
        // (leadStatus/pipeline/pipelineStage/owner) or columns were wrong this would throw.
        $result = $this->tool()->__invoke(timeframe: 'last_30_days');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('timeframe', $result);
        $this->assertArrayHasKey('from', $result['timeframe']);
        $this->assertArrayHasKey('to', $result['timeframe']);
        $this->assertArrayHasKey('by_status', $result);
        $this->assertArrayHasKey('by_stage', $result);
    }
}
