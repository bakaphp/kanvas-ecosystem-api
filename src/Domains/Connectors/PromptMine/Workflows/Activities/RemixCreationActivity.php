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
use Kanvas\Connectors\PromptMine\Notifications\MessageOwnerPushNotification;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Models\Message;
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

                if (! array_key_exists('remix_parent_id', $entity->message) || is_null($entity->message['remix_parent_id'])) {
                    return [
                        'message' => 'Message does not have a remix parent ID, not a remix or no value for remix_parent_id',
                        'message_id' => $entity->getId(),
                        'result' => false,
                    ];
                }
                $entity->parent_id = $entity->message['remix_parent_id'];
                $entity->save();
                $entity->parent->increment('total_children');

                //Send notification to the original message owner
                $endViaList = array_map(
                    [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                    $params['via'] ?? ['database']
                );

                try {
                    $remixMessage = Message::find($entity->message['remix_parent_id']);
                    $promptRemixTitle = $remixMessage->message['title'] ?? '';
                    $newMessageNotification = new MessageOwnerPushNotification(
                        user: $entity->user,
                        entity: $entity,
                        message: "Your AI creation {$promptRemixTitle} has been remixed!",
                        title: 'AI creation remixed',
                        via: $endViaList,
                        templates: [
                            'email_template' => $params['email_template'],
                            'push_template' => $params['push_template'],
                        ],
                    );
                    $remixMessage->user->notify($newMessageNotification);
                } catch (\Throwable $th) {
                    return [
                        'message' => 'Notification to remix owner failed: ' . $th->getMessage(),
                        'result' => true,
                        'message_id' => $entity->getId(),
                    ];
                }
                

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
