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
        $this->makeMessage(
            $conversation->id,
            $user->getId(),
            'user',
            'Hey, what is the EVT status?',
            $now
        );
        $this->makeMessage(
            $conversation->id,
            null,
            'assistant',
            '',
            $now->copy()->addSecond()
        );  // tool-call dispatch
        $this->makeMessage(
            $conversation->id,
            null,
            'tool_result',
            'raw schedule master rows',
            $now->copy()->addSeconds(2)
        );
        $this->makeMessage(
            $conversation->id,
            null,
            'assistant',
            'EVT validation is on track for Friday.',
            $now->copy()->addSeconds(3)
        );

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
        $this->assertStringNotContainsString(
            'ASSISTANT (',
            substr($prompt, strpos($prompt, 'EVT validation is on track for Friday.') + 1) ?: 'sentinel',
            'expected exactly one ASSISTANT line — the empty-content tool dispatch should be stripped',
        );
    }

    public function testHonorsEagerLoadedDayWindowAndDoesNotReQueryAllMessages(): void
    {
        // Regression — the builder used to call $conversation->messages()
        // (the relation method) which re-queried ALL messages, discarding
        // the day-window eager-load constraint applied by the summarize
        // action. Long-running sessions then leaked older messages into
        // "yesterday's" summary.
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        $conversation = AgentConversation::query()->create([
            'id' => 'conv-window-' . uniqid('', true),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'title' => 'long-running thread',
            'meta' => ['source' => 'slack'],
        ]);

        // Three messages: two from "yesterday" (in-window), one from a
        // week ago (out-of-window).
        $this->makeMessage($conversation->id, $user->getId(), 'user', 'older context — must be excluded', Carbon::parse('2026-05-15 10:00:00'));
        $this->makeMessage($conversation->id, $user->getId(), 'user', 'yesterday-user-msg', Carbon::parse('2026-05-22 10:00:00'));
        $this->makeMessage($conversation->id, null, 'assistant', 'yesterday-assistant-msg', Carbon::parse('2026-05-22 10:01:00'));

        // Load with the same day-window constraint the summarize action uses.
        $dayStart = Carbon::parse('2026-05-22 00:00:00', 'UTC');
        $dayEnd = Carbon::parse('2026-05-22 23:59:59', 'UTC');

        /** @var Collection<int, AgentConversation> $conversations */
        $conversations = AgentConversation::query()
            ->where('id', $conversation->id)
            ->with(['messages' => fn ($q) => $q->whereBetween('created_at', [$dayStart, $dayEnd])])
            ->get();

        $prompt = new DailyLearningPromptBuilderService()->build(
            $agent,
            '2026-05-22',
            $conversations,
        );

        // Both in-window messages present
        $this->assertStringContainsString('yesterday-user-msg', $prompt);
        $this->assertStringContainsString('yesterday-assistant-msg', $prompt);
        // Older message MUST NOT leak in
        $this->assertStringNotContainsString('older context — must be excluded', $prompt);
    }

    public function testInjectsExistingMemoryFactsForLlmSideDedup(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        /** @var Collection<int, AgentConversation> $conversations */
        $conversations = new Collection();

        $existingMemory = implode(
            "\n§\n",
            [
                'Steven Lu is the contact for PNP and check-ins.',
                'Reynaldo handles POs at felix.',
            ]
        );

        $prompt = new DailyLearningPromptBuilderService()->build(
            $agent,
            '2026-05-23',
            $conversations,
            $existingMemory,
        );

        $this->assertStringContainsString('EXISTING DURABLE MEMORY (2 facts', $prompt);
        $this->assertStringContainsString('Steven Lu is the contact for PNP', $prompt);
        $this->assertStringContainsString('Reynaldo handles POs at felix.', $prompt);
        $this->assertStringContainsString('DEDUP RULES for `durable_facts`', $prompt);
        $this->assertStringContainsString('REDUNDANT', $prompt);
        $this->assertStringContainsString('Emit CORRECTIONS as new facts', $prompt);
    }

    public function testEmptyExistingMemoryOmitsDedupBlock(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        /** @var Collection<int, AgentConversation> $conversations */
        $conversations = new Collection();

        $prompt = new DailyLearningPromptBuilderService()->build(
            $agent,
            '2026-05-23',
            $conversations,
            '', // no existing memory
        );

        $this->assertStringNotContainsString('EXISTING DURABLE MEMORY', $prompt);
        $this->assertStringNotContainsString('DEDUP RULES', $prompt);
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
