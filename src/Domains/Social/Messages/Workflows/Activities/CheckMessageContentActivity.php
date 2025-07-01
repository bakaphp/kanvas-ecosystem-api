<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Messages\Actions\CheckMessageContentAction;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CheckMessageContentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity->parent_id && $entity->message_types_id !== MessagesTypesRepository::getByVerb('prompt', $app)->getId()) {
            return [
                'message' => 'Message is not a prompt message, skipping',
                'message_id' => $entity->getId(),
                'result' => true,
            ];
        }

        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);
            $company = $branch->company;
        } catch (ModelNotFoundException $e) {
            $company = $entity->company;
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app) {
                if ((new CheckMessageContentAction($entity->message, $app))->execute()) {
                    $entity->is_public = 0;
                    $entity->is_deleted = 1;

                    return [
                        'message' => 'Message content is not allowed, message has been set to private and deleted',
                        'is_public' => false,
                        'message_id' => $entity->getId(),
                        'message_content' => $entity->message,
                        'result' => true,
                    ];
                }

                return [
                    'message' => 'No issues found in message content',
                    'message_id' => $entity->getId(),
                     'result' => true,
                ];
            },
            company: $company,
        );
    }
}
