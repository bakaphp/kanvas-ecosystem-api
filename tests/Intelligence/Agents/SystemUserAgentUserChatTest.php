<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\Stubs\Intelligence\SystemUserAgentStub;
use Tests\TestCase;

class SystemUserAgentUserChatTest extends TestCase
{
    private const string MUTATION = '
        mutation($input: UserChatInput!) {
            aiAgentUserChat(input: $input) {
                response
                session_id
                channel { id }
            }
        }
    ';

    private function makeAgent(string $handler, bool $withUser = true): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => $handler]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => $withUser ? auth()->user()->getId() : null,
            ]);
    }

    public function testSystemAgentGetsDurableUserAgentChannelSession(): void
    {
        $agent = $this->makeAgent(SystemUserAgentStub::class);

        $first = $this->graphQL(self::MUTATION, [
            'input' => ['agent_id' => (string) $agent->getId(), 'message' => 'hi'],
        ])->assertSuccessful();

        $sessionId = $first->json('data.aiAgentUserChat.session_id');
        $this->assertNotNull($sessionId);

        /** @var Session $session */
        $session = Session::where('uuid', $sessionId)->first();
        $this->assertSame(Users::class, $session->entity_namespace);
        $this->assertNotNull($session->channel_id, 'System agents must sit in a real user↔agent channel');

        // A second turn WITHOUT session_id must reuse the same durable session server-side.
        $second = $this->graphQL(self::MUTATION, [
            'input' => ['agent_id' => (string) $agent->getId(), 'message' => 'again'],
        ])->assertSuccessful();

        $this->assertSame(
            $sessionId,
            $second->json('data.aiAgentUserChat.session_id'),
            'Same user + system agent reuses one durable session without the client echoing session_id',
        );
    }

    public function testNonSystemAgentKeepsTheLegacyPerCallSession(): void
    {
        $agent = $this->makeAgent(SalesNeuronAgentStub::class);

        $first = $this->graphQL(self::MUTATION, [
            'input' => ['agent_id' => (string) $agent->getId(), 'message' => 'hi'],
        ])->assertSuccessful();

        $second = $this->graphQL(self::MUTATION, [
            'input' => ['agent_id' => (string) $agent->getId(), 'message' => 'again'],
        ])->assertSuccessful();

        // The gate must not touch legacy agents: without session_id they still mint a
        // fresh anonymous session per call (no server-side durability), unlike system agents.
        $this->assertNotSame(
            $first->json('data.aiAgentUserChat.session_id'),
            $second->json('data.aiAgentUserChat.session_id'),
            'Non-ConversesWithUser agents must keep the legacy per-call session path unchanged',
        );
    }

    public function testSystemAgentChatAboutALeadStaysOutOfTheLeadTimeline(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agent = $this->makeAgent(SystemUserAgentStub::class);
        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $response = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) { message { id } }
            }
        ', [
            'input' => [
                'agent_id' => (string) $agent->getId(),
                'message' => 'summarize what happened with this lead',
                'lead_id' => (string) $lead->getId(),
            ],
        ])->assertSuccessful();

        $replyId = $response->json('data.aiAgentUserChat.message.id');
        $this->assertNotNull($replyId);

        $attachedToLead = DB::connection('social')
            ->table('app_module_message')
            ->where('message_id', $replyId)
            ->where('system_modules', Lead::class)
            ->exists();

        $this->assertFalse(
            $attachedToLead,
            'An internal system-agent chat about a lead must NOT post its turns into the customer-facing lead timeline',
        );
    }
}
