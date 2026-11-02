<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Concerns;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\NervousSystem\DailyLearning\Services\CycleWindowResolverService;

trait SeedsDailyLearningConversations
{
    protected function seedConversationOnCompanyTimezoneDay(
        Agent $agent,
        Apps $app,
        Companies $company,
        int $userId,
        Carbon $cycleDate,
    ): void {
        // Parse the label IN the tenant zone rather than shifting into it, so noon lands inside the day window.
        $timezone = CycleWindowResolverService::resolveTimezone($app, $company);
        $messageAt = Carbon::parse($cycleDate->toDateString(), $timezone)->setTime(12, 0, 0)->utc();

        $conversation = AgentConversation::query()->create([
            'id' => 'conv-' . uniqid('', true),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'title' => 'Daily sync',
            'meta' => ['source' => 'slack'],
            'created_at' => $messageAt,
            'updated_at' => $messageAt,
        ]);

        AgentConversationMessage::query()->create([
            'id' => 'msg-' . uniqid('', true) . '-u',
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
            'agent' => 'TestAgent',
            'role' => 'user',
            'content' => 'How did the gate meeting go?',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'created_at' => $messageAt,
            'updated_at' => $messageAt,
        ]);

        AgentConversationMessage::query()->create([
            'id' => 'msg-' . uniqid('', true) . '-a',
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'agent' => 'TestAgent',
            'role' => 'assistant',
            'content' => 'It went well — Kevin signed off on the milestone.',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'created_at' => $messageAt->copy()->addSecond(),
            'updated_at' => $messageAt->copy()->addSecond(),
        ]);
    }
}
