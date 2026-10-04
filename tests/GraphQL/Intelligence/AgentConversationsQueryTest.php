<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Tests\TestCase;

class AgentConversationsQueryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testMessagesAreScopedToTheirOwnConversation(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentA = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $agentB = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $store = new KanvasConversationStore();

        $store->logTurn(
            userId: $user->getId(),
            sessionId: (string) Str::uuid(),
            agentClass: 'Test\\Stub\\HandlerA',
            userMessage: 'A says hi',
            assistantResponse: 'A replies',
            agentId: $agentA->getId(),
        );

        $store->logTurn(
            userId: $user->getId(),
            sessionId: (string) Str::uuid(),
            agentClass: 'Test\\Stub\\HandlerB',
            userMessage: 'B says hi',
            assistantResponse: 'B replies',
            agentId: $agentB->getId(),
        );

        $response = $this->graphQL('
            query {
                agentConversations(first: 25) {
                    data {
                        id
                        agent { id }
                        participant {
                            __typename
                            ... on User { id }
                        }
                        messages(first: 50) {
                            data {
                                content
                                status
                                steps
                                tool_calls
                                participant { __typename }
                            }
                        }
                    }
                }
            }
        ')->assertSuccessful();

        $conversations = $response->json('data.agentConversations.data');
        $this->assertGreaterThanOrEqual(2, count($conversations));

        $mine = collect($conversations)->first(fn (array $conv): bool => (int) $conv['agent']['id'] === $agentA->getId());
        $this->assertSame('User', $mine['participant']['__typename']);
        $this->assertSame($user->getId(), (int) $mine['participant']['id']);
        $reply = collect($mine['messages']['data'])->firstWhere('content', 'A replies');
        $this->assertSame('completed', $reply['status']);
        $this->assertSame('A replies', $reply['steps'][0]['content']);
        $this->assertSame([], $reply['tool_calls'], 'the deprecated field still answers, derived from steps');
        $this->assertSame('User', $reply['participant']['__typename']);

        $byAgent = [];
        foreach ($conversations as $conv) {
            $agentId = (int) $conv['agent']['id'];
            $contents = collect($conv['messages']['data'])->pluck('content')->all();
            $byAgent[$agentId] = $contents;
        }

        $this->assertArrayHasKey($agentA->getId(), $byAgent);
        $this->assertArrayHasKey($agentB->getId(), $byAgent);

        $this->assertContains('A says hi', $byAgent[$agentA->getId()]);
        $this->assertContains('A replies', $byAgent[$agentA->getId()]);
        $this->assertNotContains('B says hi', $byAgent[$agentA->getId()]);
        $this->assertNotContains('B replies', $byAgent[$agentA->getId()]);

        $this->assertContains('B says hi', $byAgent[$agentB->getId()]);
        $this->assertContains('B replies', $byAgent[$agentB->getId()]);
        $this->assertNotContains('A says hi', $byAgent[$agentB->getId()]);
        $this->assertNotContains('A replies', $byAgent[$agentB->getId()]);
    }

    public function testMessagesWhereCreatedAtFiltersWithinConversation(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $oldClock = Carbon::create(2026, 1, 1, 12, 0, 0);
        $newClock = Carbon::create(2026, 6, 1, 12, 0, 0);

        Carbon::setTestNow($oldClock);
        $store = new KanvasConversationStore();
        $sessionId = (string) Str::uuid();
        $store->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'old user message',
            assistantResponse: 'old assistant message',
            agentId: $agent->getId(),
        );

        Carbon::setTestNow($newClock);
        $store->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'new user message',
            assistantResponse: 'new assistant message',
            agentId: $agent->getId(),
        );
        Carbon::setTestNow();

        $conversationId = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->value('id');
        $this->assertNotNull($conversationId);

        $response = $this->graphQL('
            query {
                agentConversations(
                    where: { column: ID, operator: EQ, value: "' . $conversationId . '" }
                ) {
                    data {
                        id
                        messages(
                            where: { column: CREATED_AT, operator: GT, value: "2026-03-01 00:00:00" }
                            orderBy: [{ column: CREATED_AT, order: ASC }]
                        ) {
                            data {
                                content
                                created_at
                            }
                        }
                    }
                }
            }
        ')->assertSuccessful();

        $conversations = $response->json('data.agentConversations.data');
        $this->assertCount(1, $conversations);

        $contents = collect($conversations[0]['messages']['data'])->pluck('content')->all();
        $this->assertContains('new user message', $contents);
        $this->assertContains('new assistant message', $contents);
        $this->assertNotContains('old user message', $contents);
        $this->assertNotContains('old assistant message', $contents);
    }

    public function testQueryOnlyReturnsCurrentUsersConversations(): void
    {
        $app = app(Apps::class);
        $userA = auth()->user();
        $company = $userA->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $userA->getId()]);

        $store = new KanvasConversationStore();

        $store->logTurn(
            userId: $userA->getId(),
            sessionId: (string) Str::uuid(),
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'message from A',
            assistantResponse: 'reply to A',
            agentId: $agent->getId(),
        );

        $userB = $this->createUser();
        $this->actingAs($userB, 'api');

        $emptyResponse = $this->graphQL('
            query {
                agentConversations(first: 25) {
                    data { id title }
                }
            }
        ')->assertSuccessful();

        $this->assertSame(
            [],
            $emptyResponse->json('data.agentConversations.data'),
            'User B must not see User A conversations',
        );

        // A turn is recorded under its agent's company, so B talks to an agent of B's own company.
        $agentB = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($userB->getCurrentCompany()->getId())
            ->create(['user_id' => $userB->getId()]);

        $store->logTurn(
            userId: $userB->getId(),
            sessionId: (string) Str::uuid(),
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'message from B',
            assistantResponse: 'reply to B',
            agentId: $agentB->getId(),
        );

        $userBResponse = $this->graphQL('
            query {
                agentConversations(first: 25) {
                    data {
                        id
                        messages(first: 50) {
                            data { content }
                        }
                    }
                }
            }
        ')->assertSuccessful();

        $convs = $userBResponse->json('data.agentConversations.data');
        $this->assertCount(1, $convs);

        $contents = collect($convs[0]['messages']['data'])->pluck('content')->all();
        $this->assertContains('message from B', $contents);
        $this->assertNotContains('message from A', $contents);
    }

    public function testArchivedMessagesStayInTheTranscript(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        new KanvasConversationStore()->logTurn(
            userId: $user->getId(),
            sessionId: (string) Str::uuid(),
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'Compacted later',
            assistantResponse: 'Kept',
            agentId: $agent->getId(),
        );

        DB::connection('intelligence')
            ->table('agent_conversation_messages')
            ->where('content', 'Compacted later')
            ->update(['archived_at' => Carbon::now()]);

        $response = $this->graphQL('
            query {
                agentConversations(first: 25) {
                    data {
                        agent { id }
                        messages(first: 50) { data { content } }
                    }
                }
            }
        ')->assertSuccessful();

        $mine = collect($response->json('data.agentConversations.data'))
            ->first(fn (array $conv): bool => (int) $conv['agent']['id'] === $agent->getId());

        $this->assertEqualsCanonicalizing(['Compacted later', 'Kept'], array_column($mine['messages']['data'], 'content'));
    }
}
