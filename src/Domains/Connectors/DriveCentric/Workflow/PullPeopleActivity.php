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
            additionalParams: $params,
            integrationOperation: function ($model, $app, $integrationCompany, $additionalParams) use ($params): array {
                $pullPeople = new PullPeopleAction($app, $model->company, $model->user);

                // Pull by email or phone
                $email = $model->getEmails()->first()?->value ?? $params['email'] ?? null;
                $phone = $model->getPhones()->first()?->value ?? $params['phone'] ?? null;

                $people = $pullPeople->execute($email, $phone);

                return [
                    'message' => 'People pulled successfully',
                    'entity' => $people->toArray()
                ];
            },
            company: $model->company,
        );
    }
}
