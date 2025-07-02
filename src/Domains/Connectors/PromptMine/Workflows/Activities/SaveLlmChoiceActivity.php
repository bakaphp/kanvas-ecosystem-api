<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Enums\MessageTypeEnum;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Messages\Models\Message;
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
        $this->overwriteAppService($app);

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
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($messageData) {
                $publishedFromChat = false;
                /**
                 * @todo move this someplace else, not good having 2 logics in the same activity
                 * move this to its own activity / workflow
                 */
                if ($entity instanceof Message) {
                    $messageData = $entity->message;

                    $publishedFromChat = isset($messageData['id']) && (int) $messageData['id'] > 0 && $entity->messageType->name === MessageTypeEnum::NUGGET->value;

                    if ($publishedFromChat) {
                        $messageFromChat = Message::getById(
                            $messageData['id'],
                            $entity->app
                        )->children()->first();

                        $messageFromChat->addMessage([
                            'is_posted' => true,
                            'posted_message_id' => $entity->getId(),
                        ]);

                        if (! empty($messageFromChat->message['image'])) {
                            $entity->addMessage([
                                'image' => $messageFromChat->message['image'],
                            ]);
                        }
                    }
                }

                if (! isset($messageData['ai_model'])) {
                    return [
                        'result' => false,
                        'message' => 'Message does not have an AI model',
                        'update_chat' => $publishedFromChat,
                        'message_chat' => isset($messageFromChat) && $messageFromChat instanceof Message ? $messageFromChat->toArray() : [],
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
                    'update_chat' => $publishedFromChat,
                    'message_chat' => isset($messageFromChat) && $messageFromChat instanceof Message ? $messageFromChat->toArray() : [],
                ];
            },
            company: $company,
        );
    }
}
