<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Google\Actions\SyncUserInteractionToEventAction;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class SyncUserInteractionToEventActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

    public function execute(Model $userInteraction, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $interactionEntity = $userInteraction->entityData();

        $companyBranchId = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
        $globalAppCompany = CompaniesBranches::where('id', $companyBranchId)->first();

        $company = $interactionEntity->company ?? ($globalAppCompany ? $globalAppCompany->company : null);
        if (! $company) {
            return [
                'result' => false,
                'message' => 'Company not found',
                'slug' => $userInteraction->entity_id,
            ];
        }

        $syncUserInteraction = new SyncUserInteractionToEventAction(
            $app,
            $interactionEntity->company,
            $userInteraction->user
        );

        $result = $syncUserInteraction->execute(
            $userInteraction->interaction,
            [$userInteraction->id]
        );

        //re-generate the home feed

        return [
            'result' => $result,
            'user_interaction_id' => $userInteraction->id,
            'entity_id' => $userInteraction->entity_id,
            'entity_namespace' => $userInteraction->entity_namespace,
        ];
    }
}
