<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class MessageUsageStatsTest extends TestCase
{
    private function createMessageViaGraphQL(MessageType $messageType): int
    {
        $response = $this->graphQL('
            mutation createMessage($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
                'system_modules_id' => 1,
                'entity_id' => '1',
            ],
        ])->assertSuccessful();

        return (int) $response->json('data.createMessage.id');
    }

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

    public function testUserMessageUsageStatsCountsCreatedMessages(): void
    {
        $messageType = MessageType::factory()->create();

        $this->createMessageViaGraphQL($messageType);
        $this->createMessageViaGraphQL($messageType);
        $this->createMessageViaGraphQL($messageType);

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
        $todayCount = collect($response->json('data.userMessageUsageStats.data'))->last()['count'];

        $this->assertGreaterThanOrEqual(3, $totalCount);
        $this->assertGreaterThanOrEqual(3, $todayCount);
    }

    public function testCompanyMessageUsageStatsCountsAllUsersMessages(): void
    {
        $messageType = MessageType::factory()->create();

        $this->createMessageViaGraphQL($messageType);
        $this->createMessageViaGraphQL($messageType);

        $response = $this->graphQL('
            query($message_type_id: ID) {
                companyMessageUsageStats(days: 7, message_type_id: $message_type_id) {
                    totalCount
                    data {
                        date
                        count
                    }
                }
            }
        ', ['message_type_id' => $messageType->getId()])
        ->assertSuccessful();

        $totalCount = $response->json('data.companyMessageUsageStats.totalCount');
        $this->assertGreaterThanOrEqual(2, $totalCount);
    }

    public function testUserMessageUsageStatsTotalCountMatchesDataSum(): void
    {
        $messageType = MessageType::factory()->create();

        $this->createMessageViaGraphQL($messageType);
        $this->createMessageViaGraphQL($messageType);

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

    public function testUserMessageUsageStatsWithMessageTypeFilter(): void
    {
        $messageTypeA = MessageType::factory()->create();
        $messageTypeB = MessageType::factory()->create();

        $this->createMessageViaGraphQL($messageTypeA);
        $this->createMessageViaGraphQL($messageTypeA);
        $this->createMessageViaGraphQL($messageTypeB);

        $responseA = $this->graphQL('
            query($message_type_id: ID) {
                userMessageUsageStats(days: 7, message_type_id: $message_type_id) {
                    totalCount
                }
            }
        ', ['message_type_id' => $messageTypeA->getId()])
        ->assertSuccessful();

        $responseB = $this->graphQL('
            query($message_type_id: ID) {
                userMessageUsageStats(days: 7, message_type_id: $message_type_id) {
                    totalCount
                }
            }
        ', ['message_type_id' => $messageTypeB->getId()])
        ->assertSuccessful();

        $countA = $responseA->json('data.userMessageUsageStats.totalCount');
        $countB = $responseB->json('data.userMessageUsageStats.totalCount');

        $this->assertGreaterThanOrEqual(2, $countA);
        $this->assertGreaterThanOrEqual(1, $countB);
        $this->assertGreaterThan($countB, $countA);
    }
}
