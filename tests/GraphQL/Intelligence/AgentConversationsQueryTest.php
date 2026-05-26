<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Tests\TestCase;

class AgentConversationsQueryTest extends TestCase
{
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
                        messages(first: 50) {
                            data {
                                content
                            }
                        }
                    }
                }
            }
        ')->assertSuccessful();

        $conversations = $response->json('data.agentConversations.data');
        $this->assertGreaterThanOrEqual(2, count($conversations));

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
}
