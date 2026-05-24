<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\NervousSystem\DailyLearning\Services\DailyLearningPromptBuilderService;
use Tests\TestCase;

class DailyLearningPromptBuilderServiceTest extends TestCase
{
    public function testStripsToolNoiseAndIncludesUserAssistantContent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        $conversation = AgentConversation::query()->create([
            'id' => 'conv-' . uniqid('', true),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'title' => 'Daily sync with Kevin',
            'meta' => ['source' => 'slack'],
        ]);

        $now = Carbon::now();
        $this->makeMessage($conversation->id, $user->getId(), 'user', 'Hey, what is the EVT status?', $now);
        $this->makeMessage($conversation->id, null, 'assistant', '', $now->copy()->addSecond());  // tool-call dispatch
        $this->makeMessage($conversation->id, null, 'tool_result', 'raw schedule master rows', $now->copy()->addSeconds(2));
        $this->makeMessage($conversation->id, null, 'assistant', 'EVT validation is on track for Friday.', $now->copy()->addSeconds(3));

        /** @var Collection<int, AgentConversation> $conversations */
        $conversations = AgentConversation::query()
            ->where('id', $conversation->id)
            ->with('messages')
            ->get();

        $prompt = new DailyLearningPromptBuilderService()->build(
            $agent,
            '2026-05-23',
            $conversations,
        );

        $this->assertStringContainsString('felix-sales', $prompt);
        $this->assertStringContainsString('2026-05-23', $prompt);
        $this->assertStringContainsString('Hey, what is the EVT status?', $prompt);
        $this->assertStringContainsString('EVT validation is on track for Friday.', $prompt);

        // Tool-result and empty-content assistant rows must be filtered out
        $this->assertStringNotContainsString('raw schedule master rows', $prompt);
        $this->assertStringNotContainsString('ASSISTANT (', substr($prompt, strpos($prompt, 'EVT validation is on track for Friday.') + 1) ?: 'sentinel',
            'expected exactly one ASSISTANT line — the empty-content tool dispatch should be stripped',
        );
    }

    public function testHandlesAgentWithEmptyName(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => '']);

        /** @var Collection<int, AgentConversation> $conversations */
        $conversations = new Collection();

        $prompt = new DailyLearningPromptBuilderService()->build($agent, '2026-05-23', $conversations);

        $this->assertStringContainsString('agent #' . $agent->getId(), $prompt);
        // Empty transcript should produce a stable sentinel rather than blowing up
        $this->assertStringContainsString('(no conversations)', $prompt);
    }

    private function makeMessage(string $conversationId, ?int $userId, string $role, string $content, Carbon $createdAt): void
    {
        AgentConversationMessage::query()->create([
            'id' => 'msg-' . uniqid('', true),
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => 'TestAgent',
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
