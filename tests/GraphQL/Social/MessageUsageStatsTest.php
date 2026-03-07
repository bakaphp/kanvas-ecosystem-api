<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class MessageUsageStatsTest extends TestCase
{
    public function testUserMessageUsageStatsDefaultDays(): void
    {
        $this->graphQL('
            query {
                userMessageUsageStats {
                    period {
                        start
                        end
                        days
                    }
                    totalCount
                    data {
                        date
                        count
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'userMessageUsageStats' => [
                    'period' => ['start', 'end', 'days'],
                    'totalCount',
                    'data' => [['date', 'count']],
                ],
            ],
        ])
        ->assertJsonPath('data.userMessageUsageStats.period.days', 7);
    }

    public function testUserMessageUsageStatsReturnsExactDaysCount(): void
    {
        $response = $this->graphQL('
            query {
                userMessageUsageStats(days: 30) {
                    period {
                        days
                    }
                    data {
                        date
                        count
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonPath('data.userMessageUsageStats.period.days', 30);

        $this->assertCount(30, $response->json('data.userMessageUsageStats.data'));
    }

    public function testUserMessageUsageStatsCapsAtThirtyDays(): void
    {
        $response = $this->graphQL('
            query {
                userMessageUsageStats(days: 99) {
                    period {
                        days
                    }
                    data {
                        date
                        count
                    }
                }
            }
        ')
        ->assertSuccessful();

        $this->assertCount(30, $response->json('data.userMessageUsageStats.data'));
    }

    public function testCompanyMessageUsageStats(): void
    {
        $this->graphQL('
            query {
                companyMessageUsageStats {
                    period {
                        start
                        end
                        days
                    }
                    totalCount
                    data {
                        date
                        count
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'companyMessageUsageStats' => [
                    'period' => ['start', 'end', 'days'],
                    'totalCount',
                    'data' => [['date', 'count']],
                ],
            ],
        ])
        ->assertJsonPath('data.companyMessageUsageStats.period.days', 7);
    }

    public function testUserMessageUsageStatsZeroFillsDaysWithNoMessages(): void
    {
        $response = $this->graphQL('
            query {
                userMessageUsageStats(days: 7) {
                    data {
                        date
                        count
                    }
                }
            }
        ')
        ->assertSuccessful();

        $data = $response->json('data.userMessageUsageStats.data');

        $this->assertCount(7, $data);

        foreach ($data as $entry) {
            $this->assertArrayHasKey('date', $entry);
            $this->assertArrayHasKey('count', $entry);
            $this->assertGreaterThanOrEqual(0, $entry['count']);
        }
    }

    public function testUserMessageUsageStatsWithMessageTypeFilter(): void
    {
        $messageType = MessageType::factory()->create();

        $this->graphQL('
            query($message_type_id: ID) {
                userMessageUsageStats(days: 7, message_type_id: $message_type_id) {
                    period {
                        days
                    }
                    totalCount
                    data {
                        date
                        count
                    }
                }
            }
        ', ['message_type_id' => $messageType->getId()])
        ->assertSuccessful()
        ->assertJsonPath('data.userMessageUsageStats.period.days', 7);
    }

    public function testUserMessageUsageStatsTotalCountMatchesData(): void
    {
        $messageType = MessageType::factory()->create();

        Message::factory()->count(3)->create([
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'companies_id' => $this->user->getCurrentCompany()->getId(),
            'message_types_id' => $messageType->getId(),
        ]);

        $response = $this->graphQL('
            query($message_type_id: ID) {
                userMessageUsageStats(days: 7, message_type_id: $message_type_id) {
                    totalCount
                    data {
                        date
                        count
                    }
                }
            }
        ', ['message_type_id' => $messageType->getId()])
        ->assertSuccessful();

        $totalCount = $response->json('data.userMessageUsageStats.totalCount');
        $dataSum = array_sum(array_column($response->json('data.userMessageUsageStats.data'), 'count'));

        $this->assertEquals($totalCount, $dataSum);
    }
}
