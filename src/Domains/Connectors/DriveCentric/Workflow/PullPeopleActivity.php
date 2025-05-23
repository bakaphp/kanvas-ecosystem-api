<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Workflow;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PullPeopleAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PullPeopleActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(EloquentModel $model, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $model,
            app: $app,
            integration: IntegrationsEnum::DRIVE_CENTRIC,
            integrationOperation: function ($model, $app, $integrationCompany, $additionalParams) use ($params): array {
                $pullPeople = new PullPeopleAction($app, $model->company, $model->user)->execute(
                    $model->getEmails()->first()->value ?? null,
                    $model->getPhones()->first()->value ?? null,
                );

                return [
                    'message' => 'People pulled successfully',
                    'entity' => $pullPeople,
                ];
            },
            company: $model->company,
        );
    }
}
