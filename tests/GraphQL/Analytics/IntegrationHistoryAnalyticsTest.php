<?php

declare(strict_types=1);

namespace Tests\GraphQL\Analytics;

use Carbon\Carbon;
use Tests\TestCase;

class IntegrationHistoryAnalyticsTest extends TestCase
{
    public function testIntegrationHistoryAnalyticsHappyPath(): void
    {
        $response = $this->graphQL('
            query($from: Date!, $to: Date!) {
                integrationHistoryAnalytics(from: $from, to: $to, bucket: DAY) {
                    total
                    periods { period count }
                    by_integration { name key count }
                    by_status { name key count }
                    by_entity_namespace { name key count }
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
                    'integrationHistoryAnalytics' => [
                        'total',
                        'periods',
                        'by_integration',
                        'by_status',
                        'by_entity_namespace',
                    ],
                ],
            ]);
    }
}
