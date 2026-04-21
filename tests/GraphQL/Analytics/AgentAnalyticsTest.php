<?php

declare(strict_types=1);

namespace Tests\GraphQL\Analytics;

use Carbon\Carbon;
use Tests\TestCase;

class AgentAnalyticsTest extends TestCase
{
    public function testAgentAnalyticsHappyPath(): void
    {
        $response = $this->graphQL('
            query($from: Date!, $to: Date!) {
                agentAnalytics(from: $from, to: $to, bucket: WEEK) {
                    total
                    periods { period count }
                    by_type { name key count }
                    by_model { name key count }
                    by_deployment_status { name key count }
                    by_user { name key count }
                }
            }
        ', [
            'from' => Carbon::now()->subDays(30)->toDateString(),
            'to' => Carbon::now()->toDateString(),
        ]);

        if ($response->json('errors')) {
            $this->fail('GraphQL errors: ' . json_encode($response->json('errors')));
        }

        $response
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'agentAnalytics' => [
                        'total',
                        'periods',
                        'by_type',
                        'by_model',
                        'by_deployment_status',
                        'by_user',
                    ],
                ],
            ]);
    }
}
