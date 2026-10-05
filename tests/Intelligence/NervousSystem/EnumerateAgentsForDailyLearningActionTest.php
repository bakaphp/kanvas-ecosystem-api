<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\NervousSystem\DailyLearning\Actions\EnumerateAgentsForDailyLearningAction;
use Kanvas\NervousSystem\DailyLearning\Services\CycleWindowResolverService;
use Tests\TestCase;

class EnumerateAgentsForDailyLearningActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testAConversationWithOnlyFailedTurnsDoesNotCountAsActivity(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $conversation = AgentConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'title' => 'failed-only',
        ]);

        $cycleDate = Carbon::now(CycleWindowResolverService::resolveTimezone($app, $company));

        $this->seedMessage($conversation->id, $user->getId(), AgentConversationMessage::STATUS_FAILED);

        $agents = new EnumerateAgentsForDailyLearningAction($app, $company, $cycleDate)->execute();
        $this->assertFalse($agents->contains('id', $agent->getId()));

        $this->seedMessage($conversation->id, $user->getId(), AgentConversationMessage::STATUS_COMPLETED);

        $agents = new EnumerateAgentsForDailyLearningAction($app, $company, $cycleDate)->execute();
        $this->assertTrue($agents->contains('id', $agent->getId()));
    }

    private function seedMessage(string $conversationId, int $userId, string $status): void
    {
        AgentConversationMessage::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => 'Stub\\Agent',
            'role' => 'assistant',
            'status' => $status,
            'content' => 'turn',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
