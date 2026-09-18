<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * One chat turn has two writers into `agent_conversations` — the agent's own history and the end-of-turn
 * `logTurn` — and both must land on the same conversation. When they didn't, the chat opened as two.
 */
class ConversationSessionBindingTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    private Users $user;
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = auth()->user();
        $this->agent = $this->agent();
    }

    /** A queue worker has no authenticated user; the tenant must come from the agent, not fall to company 0. */
    public function testATurnLoggedWithoutAuthRecordsUnderTheAgentsCompany(): void
    {
        $session = (string) Str::uuid();
        Auth::forgetGuards();
        $this->assertNull(auth()->user());

        $this->logTurn($session);

        $this->assertSame(
            [$this->agent->company->getId()],
            $this->conversations($session)->pluck('companies_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function testTheHistoryAndTheTurnLogShareOneConversation(): void
    {
        $session = (string) Str::uuid();

        $fromHistory = $this->conversationForSession($session);
        Auth::forgetGuards();
        $this->logTurn($session);

        $this->assertSame([$fromHistory], $this->conversations($session)->pluck('id')->all());
    }

    /** Duplicates already on disk must resolve the same way every time, or turns alternate between them. */
    public function testTheOldestConversationWinsWhenTwoShareASession(): void
    {
        $session = (string) Str::uuid();
        $oldest = $this->conversationForSession($session);
        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->user->getId(),
            'agent_id' => $this->agent->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->agent->company->getId(),
            'title' => $session,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($oldest, $this->conversationForSession($session));
    }

    /** A channel session key leaves the agent out, so two agents on one channel share it. */
    public function testASessionLookupBindsToTheAgentsOwnRow(): void
    {
        $uuid = (string) Str::uuid();
        $mine = $this->sessionRow($uuid, $this->agent);
        $this->sessionRow($uuid, $this->agent());

        $this->assertSame($mine->getId(), Session::query()->fromAgent($this->agent)->where('uuid', $uuid)->first()?->getId());
    }

    public function testTheNewestRowWinsWhenAnAgentHasTwoUnderOneKey(): void
    {
        $uuid = (string) Str::uuid();
        $this->sessionRow($uuid, $this->agent);
        $newest = $this->sessionRow($uuid, $this->agent);

        $this->assertSame($newest->getId(), Session::query()->fromAgent($this->agent)->where('uuid', $uuid)->first()?->getId());
    }

    private function logTurn(string $session): void
    {
        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $session,
            agentClass: 'TestAgent',
            userMessage: 'hello',
            assistantResponse: 'hi',
            agentId: $this->agent->getId(),
        );
    }

    private function conversationForSession(string $session): string
    {
        return new KanvasConversationStore()->conversationForSession(
            userId: $this->user->getId(),
            sessionId: $session,
            agentId: $this->agent->getId(),
            appsId: app(Apps::class)->getId(),
            companiesId: $this->agent->company->getId(),
        );
    }

    private function conversations(string $session): Collection
    {
        return DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $session)
            ->orderBy('id')
            ->get();
    }

    private function agent(): Agent
    {
        $app = app(Apps::class);
        $type = AgentType::factory()->withAppId($app->getId())->create();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($this->user->getCurrentCompany()->getId())
            ->create(['agent_type_id' => $type->getId()]);
    }

    private function sessionRow(string $uuid, Agent $agent): Session
    {
        return Session::create([
            'uuid' => $uuid,
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agents_id' => $agent->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $this->user->getId(),
            'user' => [],
            'content' => [],
        ]);
    }
}
