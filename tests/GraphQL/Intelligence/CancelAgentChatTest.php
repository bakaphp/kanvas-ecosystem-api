<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Services\AgentTurnCancellationService;
use Tests\Stubs\Intelligence\FakeAgentHandler;
use Tests\TestCase;

class CancelAgentChatTest extends TestCase
{
    use DatabaseTransactions;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['handler' => FakeAgentHandler::class]);
        $this->agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);
    }

    public function testCancelFlagsTheSessionsThreadForTheRunningTurn(): void
    {
        $seed = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) { session_id }
            }
        ', ['input' => ['agent_id' => (string) $this->agent->getId(), 'message' => 'Turn one']])->assertSuccessful();
        $sessionId = $seed->json('data.aiAgentUserChat.session_id');

        $response = $this->cancel($sessionId);

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.aiAgentCancelChat'));
        // userChat threads the turn by the session uuid, so that is the key the middleware reads.
        $this->assertTrue(AgentTurnCancellationService::isRequested($sessionId));

        AgentTurnCancellationService::clear($sessionId);
    }

    public function testASessionOfAnotherAgentCannotBeCancelled(): void
    {
        $response = $this->cancel((string) Str::uuid());

        $this->assertStringContainsString('Session not found', (string) $response->json('errors.0.message'));
    }

    private function cancel(string $sessionId)
    {
        return $this->graphQL('
            mutation($input: CancelAgentChatInput!) {
                aiAgentCancelChat(input: $input)
            }
        ', ['input' => ['agent_id' => (string) $this->agent->getId(), 'session_id' => $sessionId]]);
    }
}
