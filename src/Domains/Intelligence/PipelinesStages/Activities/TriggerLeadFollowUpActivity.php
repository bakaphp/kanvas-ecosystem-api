<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Fires a follow-up for a single lead on demand by delegating to the
 * notification-engagement command scoped to that lead. The command keeps the
 * engine auto-selection (ManlyHonda / V2 / V1) and all skip conditions; the
 * only relaxation is the "minutes since last message" time gate, which the
 * command bypasses non-destructively when --ignore-time is set (no message
 * timestamps are rewritten).
 */
#[WorkflowAction]
class TriggerLeadFollowUpActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Lead) {
            return ['error' => 'Entity must be a Lead'];
        }

        $exitCode = Artisan::call('intelligence:notification-engagement', [
            'apps' => [$app->getId()],
            '--company_id' => $entity->companies_id,
            '--lead_id' => $entity->getId(),
            '--ignore-time' => (int) ($params['ignore_time'] ?? 1),
            '--ignore-have-follow-up' => (int) ($params['ignore_have_follow_up'] ?? 1),
            '--ignore-first-message' => (int) ($params['ignore_first_message'] ?? 1),
        ]);

        return [
            'lead_id' => $entity->getId(),
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }
}
