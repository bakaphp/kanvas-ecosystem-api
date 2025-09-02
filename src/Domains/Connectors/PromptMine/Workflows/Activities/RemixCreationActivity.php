<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class RemixCreationActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $messageType = MessageType::fromApp($entity->app)->where('verb', 'prompt')->firstOrFail()->getId();

        if ((is_array($entity->message) && ! array_key_exists('remix_parent_id', $entity->message)) && $entity->message_types_id !== $messageType) {
            return [
                'result' => false,
                'message' => 'Message does not have a remix parent ID, not a remix',
                'message_id' => $entity->getId(),
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
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params) {
                // Assign the remix_parent_id as the parent_id of the message, creating a remix.
                $entity->parent_id = $entity->message['remix_parent_id'];
                $entity->save();
                $entity->parent->increment('total_children');

                return [
                    'message' => 'Remix created successfully',
                    'result' => true,
                    'user_id' => $entity->user->getId(),
                    'message_data' => $entity->message,
                    'message_id' => $entity->getId(),
                ];
            },
            company: $company,
        );
    }
}
