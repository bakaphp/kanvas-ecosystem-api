<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\AddEntityIntegrationHistoryAction;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory as ModelsEntityIntegrationHistory;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Throwable;

/**
 * The one visible log of an activity run, written by the engine so it does not depend on the
 * activity calling `executeIntegration` itself. Rows are the budget here, not bytes: a failed run
 * is always recorded, a successful one only when its rule asks for it (`params.log_success`), and
 * an activity that already wrote its own history row is never recorded twice.
 */
class ActivityRunRecorderService
{
    public const string LOG_SUCCESS_PARAM = 'log_success';

    public function record(
        KanvasActivity $activity,
        Model $entity,
        AppInterface $app,
        array $params,
        mixed $response,
        ?Throwable $exception = null
    ): ?ModelsEntityIntegrationHistory {
        $own = $activity->lastIntegrationHistory();

        if ($own !== null) {
            return $own;
        }

        $rule = $params['rule'] ?? null;
        $rule = $rule instanceof Rule ? $rule : null;
        $failed = $exception !== null || $activity->workflowStatus() === StatusEnum::FAILED;

        if (! $failed && ! $this->logsSuccess($rule)) {
            return null;
        }

        try {
            $action = Action::forHandler($activity::class);
            $status = $activity->getStatus($failed ? StatusEnum::FAILED : StatusEnum::CONNECTED);
            $company = $entity->companies_id ? Companies::getById((int) $entity->companies_id) : null;

            if ($status === null) {
                return null;
            }

            $dto = new EntityIntegrationHistory(
                app: $app,
                integrationCompany: null,
                status: $status,
                entity: $entity,
                rule: $rule,
                input: $activity->buildIntegrationHistoryInput($entity, $params),
                response: $response,
                exception: $exception,
                workflowId: $activity->workflowId(),
                action: $action,
                integration: $action?->integration ? Integrations::where('name', $action->integration)->first() : null,
                company: $company,
            );

            return new AddEntityIntegrationHistoryAction($dto, $app, $status)->execute();
        } catch (Throwable $recordingFailure) {
            // Never let the log sink the run it is logging.
            report($recordingFailure);

            return null;
        }
    }

    private function logsSuccess(?Rule $rule): bool
    {
        $params = is_array($rule?->params) ? $rule->params : [];

        return filter_var($params[self::LOG_SUCCESS_PARAM] ?? false, FILTER_VALIDATE_BOOL);
    }
}
