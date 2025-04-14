<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\UserConfig;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SaveLlmChoiceActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $messageData = ! is_array($entity->message) ? json_decode($entity->message, true) : $entity->message;

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
            integrationOperation: function ($entity) use ($messageData) {
                if (! isset($messageData['ai_model'])) {
                    return [
                        'result' => false,
                        'message' => 'Message does not have an AI model',
                    ];
                }
                
                UserConfig::updateOrCreate(
                    [
                        'users_id' => $entity->user->getId(),
                        'name' => 'llm_last_choice',
                    ],
                    [
                        'value' => $messageData['ai_model'],
                        'is_public' => 1,
                    ],
                );

                return [
                    'message' => 'LLM choice saved',
                    'result' => true,
                    'user_id' => $entity->user->getId(),
                    'model' => $messageData['ai_model'],
                    'message_data' => $entity->message,
                    'message_id' => $entity->getId(),
                ];
            },
            company: $company,
        );
    }
}
