<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CompanyConfigurationAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Social\Messages\Models\Message;
use Mockery;
use Tests\Stubs\Intelligence\FakeAgentHandler;
use Tests\TestCase;

class AppGlobalAgentVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private function caller(bool $admin): void
    {
        $caller = Mockery::mock(auth()->user());
        $caller->shouldReceive('isAdmin')->andReturn($admin);
        $this->actingAs($caller);
    }

    private function agent(int $companyId, string $handler, ?int $appId = null): Agent
    {
        $appId ??= app(Apps::class)->getId();
        $type = AgentType::factory()->withAppId($appId)->create([
            'handler' => $handler,
            'provider' => 'neuron',
            'is_active' => true,
        ]);

        return Agent::factory()->withAppId($appId)->withCompanyId($companyId)->create([
            'agent_type_id' => $type->getId(),
            'is_active' => true,
            'is_sub_agent' => false,
            'parent_id' => null,
        ]);
    }

    public function testAdminRosterIncludesOnlyTheAuthorizedGlobalType(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $local = $this->agent($company->getId(), SystemUserAgent::class);
        $global = $this->agent(0, CompanyConfigurationAgent::class);
        $otherGlobal = $this->agent(0, SystemUserAgent::class);
        $foreignCompany = Companies::factory()->create(['users_id' => auth()->id()]);
        $foreign = $this->agent($foreignCompany->getId(), CompanyConfigurationAgent::class);
        $otherApp = $this->agent(0, CompanyConfigurationAgent::class, (int) Apps::max('id') + 1);
        $this->caller(true);

        $response = $this->graphQL('query($ids: Mixed!) {
            agentsAi(first: 50, where: {column: ID, operator: IN, value: $ids}) {
                data { id name }
            }
        }', ['ids' => [$local->getId(), $global->getId(), $otherGlobal->getId(), $foreign->getId(), $otherApp->getId()]]);
        $response->assertJsonMissingPath('errors');
        $ids = array_map('intval', array_column($response->json('data.agentsAi.data'), 'id'));
        $this->assertEqualsCanonicalizing([$local->getId(), $global->getId()], $ids);
    }

    public function testNonAdminCannotListOrOpenConfigurationGlobalAgent(): void
    {
        $global = $this->agent(0, CompanyConfigurationAgent::class);
        $this->caller(false);
        $response = $this->graphQL('query($id: Mixed!) {
            agentsAi(where: {column: ID, operator: EQ, value: $id}) { data { id } }
        }', ['id' => $global->getId()]);
        $response->assertJsonMissingPath('errors');
        $this->assertSame([], $response->json('data.agentsAi.data'));

        $chat = $this->graphQL('mutation($input: UserChatInput!) {
            aiAgentUserChat(input: $input) { response }
        }', ['input' => ['agent_id' => $global->getId(), 'message' => 'Hello']]);
        $this->assertNotEmpty($chat->json('errors'));
    }

    public function testGlobalChatPersistsUnderTheRequestingCompany(): void
    {
        Notification::fake();
        $user = auth()->user();
        $company = auth()->user()->getCurrentCompany();
        $global = $this->agent(0, FakeAgentHandler::class);
        $response = $this->graphQL('mutation($input: UserChatInput!) {
            aiAgentUserChat(input: $input) { response session_id }
        }', ['input' => ['agent_id' => $global->getId(), 'message' => 'Hello']]);
        $response->assertJsonMissingPath('errors');
        $this->assertSame('This is a fake agent response.', $response->json('data.aiAgentUserChat.response'));
        $history = AgentHistory::query()->where('agent_id', $global->getId())->first();
        $this->assertNotNull($history);
        $this->assertSame($company->getId(), (int) $history->companies_id);
        $this->assertSame(0, (int) $global->fresh()->companies_id);
        Notification::assertSentTo($user, AgentReplyNotification::class, function (AgentReplyNotification $notification) use ($user, $company): bool {
            $data = $notification->toOneSignal($user)['data'];

            return (int) $data['company_id'] === $company->getId()
                && $data['company_uuid'] === $company->uuid
                && $data['company'] === $company->name;
        });
    }

    public function testGlobalReplyNotificationUsesTheMessageCompany(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = new Agent(['id' => 123, 'name' => 'Global agent', 'companies_id' => 0]);
        $agent->setRelation('company', null);
        $reply = new Message([
            'id' => 456,
            'companies_id' => $company->getId(),
            'message' => ['content' => 'Hello', 'session_id' => 'test-session'],
        ]);
        $reply->setRelation('app', app(Apps::class));
        $reply->setRelation('company', $company);
        $reply->syncOriginal();

        $notification = new AgentReplyNotification($reply, $agent, $user);
        $push = $notification->toOneSignal($user);
        $this->assertSame('Hello', $push['message']);
        $this->assertSame($company->getId(), (int) $push['data']['company_id']);
        $this->assertSame($company->uuid, $push['data']['company_uuid']);
        $this->assertSame($company->name, $push['data']['company']);
    }
}
