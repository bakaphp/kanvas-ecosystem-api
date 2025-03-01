<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Recombee\Services\RecombeeInteractionService;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class PushUserInteractionToEventActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * @param \Kanvas\Social\Interactions\Models\UsersInteractions $userInteraction
     */
    #[Override]
    public function execute(Model $userInteraction, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        try {
            $interactionEntity = $userInteraction->entityData();
        } catch (ModelNotFoundException $e) {
            return [
                'result' => false,
                'message' => 'Entity not found',
                'user_interaction' => $userInteraction->toArray(),
            ];
        }

        $companyBranchId = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
        $globalAppCompany = CompaniesBranches::where('id', $companyBranchId)->first();

        $company = $interactionEntity->company ?? ($globalAppCompany ? $globalAppCompany->company : null);
        if ($company === null) {
            return [
                'result' => false,
                'message' => 'Company not found',
                'slug' => $userInteraction->entity_id,
            ];
        }

        $recombeeIndex = new RecombeeInteractionService($app);

        try {
            $result = $recombeeIndex->addUserInteraction($userInteraction);
        } catch (Throwable $e) {
            return [
                'result' => false,
                'message' => $e->getMessage(),
                'user_interaction_id' => $userInteraction->id,
                'entity_id' => $userInteraction->entity_id,
                'entity_namespace' => $userInteraction->entity_namespace,
            ];
        }

        //re-generate the home feed

        return [
            'result' => $result,
            'user_interaction_id' => $userInteraction->id,
            'entity_id' => $userInteraction->entity_id,
            'entity_namespace' => $userInteraction->entity_namespace,
        ];
    }
}
