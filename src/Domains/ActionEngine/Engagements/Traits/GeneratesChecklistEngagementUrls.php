<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Traits;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\Cache;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement as EngagementModel;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

trait GeneratesChecklistEngagementUrls
{
    protected function generateChecklistEngagementUrls(Lead $lead): array
    {
        $actions = [
            'creditApp' => 'credit-app',
            'tradeIn' => 'add-trade',
        ];

        $results = [];
        $user = $lead->company->getAiAgentUser() ?? $lead->user;

        foreach ($actions as $key => $action) {
            try {
                $engagement = $this->getOrCreateChecklistEngagement($lead, $action, $user);
                if ($engagement === null) {
                    continue;
                }

                $engagement->message->is_public = 0;
                $engagement->message->saveQuietly();

                $results[$key] = $engagement->message->message['action_link'] ?? null;
            } catch (Exception) {
                $results[$key] = null;
            }
        }

        return $results;
    }

    private function getOrCreateChecklistEngagement(Lead $lead, string $action, Users $user): ?EngagementModel
    {
        $lockKey = "engagement_creation:{$lead->id}:{$action}";

        return Cache::lock($lockKey, 10)->block(10, function () use ($lead, $action, $user): EngagementModel {
            $engagementAction = new CreateEngagementAction(
                Engagement::from(
                    $lead->app,
                    $lead->company,
                    $user,
                    $lead,
                    [
                        'action' => $action,
                        'request_id' => Str::uuid()->toString(),
                        'source' => 'ai',
                        'status' => 'sent',
                        'data' => [],
                    ],
                    $lead->people,
                ),
                false,
            );

            return $engagementAction->execute();
        });
    }
}
