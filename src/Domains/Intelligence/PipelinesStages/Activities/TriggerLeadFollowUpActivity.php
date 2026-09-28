<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Fires a follow-up for a single lead on demand by delegating to the
 * notification-engagement command scoped to that lead. The command keeps the
 * engine selection (ManlyHonda or the current follow-up engine) and all skip conditions; the
 * only relaxation is the "minutes since last message" time gate, which the
 * command bypasses non-destructively when --ignore-time is set (no message
 * timestamps are rewritten).
 *
 * @deprecated rides the legacy pipeline-stage follow-up engine
 *             ({@see \App\Console\Commands\Intelligence\FollowUp\FollowUpEngagementCommand}).
 *             Use the v1 engine — {@see \Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction}
 *             (manual single-lead trigger) — instead. Slated for deletion with the
 *             rest of the engine; see docs/intelligence/follow-up-deprecation-spec.md kill list.
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

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($entity, $app, $params) {
                $exitCode = Artisan::call('intelligence:notification-engagement', [
                    'apps' => [$app->getId()],
                    '--company_id' => $entity->companies_id,
                    '--lead_id' => $entity->getId(),
                    '--ignore-time' => (int) ($params['ignore_time'] ?? 1),
                    '--template' => $params['template'] ?? null,
                ]);

                return [
                    'lead_id' => $entity->getId(),
                    'exit_code' => $exitCode,
                    'output' => Artisan::output(),
                ];
            },
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
