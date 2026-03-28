<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\FakeAgentHandler;
use Tests\TestCase;

class UserAgentChatTest extends TestCase
{
    use DatabaseTransactions;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->id)
            ->create([
                'handler' => FakeAgentHandler::class,
            ]);

        $this->agent = Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'agent_type_id' => $agentType->id,
            ]);
    }

    public function testUserChatCreatesSessionAndReturnsResponse(): void
    {
        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello, agent!',
            ],
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'aiAgentUserChat' => [
                    'response',
                    'session_id',
                ],
            ],
        ]);

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $this->assertNotEmpty($sessionId);
        $this->assertNotEmpty($response->json('data.aiAgentUserChat.response'));
    }

    public function testUserChatWithSessionIdResumesSession(): void
    {
        $firstResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello, agent!',
            ],
        ]);

        $firstResponse->assertSuccessful();
        $sessionId = $firstResponse->json('data.aiAgentUserChat.session_id');

        $secondResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Follow-up message',
                'session_id' => $sessionId,
            ],
        ]);

        $secondResponse->assertSuccessful();
        $this->assertEquals($sessionId, $secondResponse->json('data.aiAgentUserChat.session_id'));
    }

    public function testUserChatWithoutSessionIdAlwaysCreatesNewSession(): void
    {
        $firstResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'First message',
            ],
        ]);

        $firstResponse->assertSuccessful();
        $firstSessionId = $firstResponse->json('data.aiAgentUserChat.session_id');

        $secondResponse = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Second message',
            ],
        ]);

        $secondResponse->assertSuccessful();
        $secondSessionId = $secondResponse->json('data.aiAgentUserChat.session_id');

        $this->assertNotEquals($firstSessionId, $secondSessionId);
    }

    public function testUserChatWithLeadId(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello from lead context',
                'lead_id' => (string) $lead->getId(),
            ],
        ]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $this->assertNotEmpty($sessionId);

        $session = Session::where('uuid', $sessionId)->first();
        $this->assertNotNull($session);
        $this->assertEquals(Lead::class, $session->entity_namespace);
        $this->assertEquals($lead->getId(), $session->entity_id);
    }

    public function testUserChatWithoutLeadIdCreatesUserSession(): void
    {
        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Hello from user context',
            ],
        ]);

        $response->assertSuccessful();

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $session = Session::where('uuid', $sessionId)->first();

        $this->assertNotNull($session);
        $this->assertEquals(Users::class, $session->entity_namespace);
    }

    public function testAgentHistoryTracksUserId(): void
    {
        $user = auth()->user();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'message' => 'Track my user ID',
            ],
        ]);

        $response->assertSuccessful();

        $history = AgentHistory::where('agent_id', $this->agent->getId())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals($user->getId(), $history->users_id);
    }

    public function testExistingLeadFlowUnchanged(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create();

        $createSessionResponse = $this->graphQL('
            mutation($input: AgentSessionInput!) {
                aiAgentCreateSession(input: $input)
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'canal_id' => 'test-canal',
                'lead_id' => (string) $lead->getId(),
            ],
        ]);

        $createSessionResponse->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $createSessionResponse->json(), 'GraphQL errors: ' . $createSessionResponse->getContent());
        $sessionId = $createSessionResponse->json('data.aiAgentCreateSession');
        $this->assertNotEmpty($sessionId);

        $chatResponse = $this->graphQL('
            mutation($input: ChatSimpleInput!) {
                aiAgentChat(input: $input)
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'session_id' => $sessionId,
                'message' => 'Hello via legacy flow',
            ],
        ]);

        $chatResponse->assertSuccessful();
        $this->assertNotEmpty($chatResponse->json('data.aiAgentChat'));
    }
}
