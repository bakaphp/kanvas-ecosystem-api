<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Notifications\MessageInteractionNotification;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class MessageOwnerInteractionNotifierActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $emailTemplate = $params['email_template'] ?? null;
        $pushTemplate = $params['push_template'] ?? null;
        $interaction = $params['interaction'] ?? null;
        $userInteraction = $params['user_interaction'] ?? null;

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use (
                $emailTemplate,
                $pushTemplate,
                $interaction,
                $userInteraction,
                $params
            ) {
                if ($interaction === null) {
                    return [
                        'result' => false,
                        'message' => 'Interaction is required',
                        'params' => $params,
                    ];
                }

                if ($userInteraction === null) {
                    return [
                        'result' => false,
                        'message' => 'User Interaction is required',
                        'params' => $params,
                    ];
                }

                $interactionType = ucfirst($interaction); // Capitalize interaction for consistency
                $notificationMessage = $params['message'] ?? 'New %s from %s on your message';
                $notificationTitle = $params['title'] ?? 'New ' . $interactionType;
                $subject = $params['subject'] ?? $notificationTitle . ' from %s';
                $viaList = $params['via'] ?? ['database'];

                // Map notification channels
                $endViaList = array_map(
                    [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                    $viaList
                );

                $metaData = $message->getMessage();
                $keysToUnset = ['ai_nugged', 'nugget'];
                foreach ($keysToUnset as $key) {
                    unset($metaData[$key]); // @todo move this to a customization
                }

                $keysToClear = ['prompt', 'image'];
                foreach ($keysToClear as $key) {
                    if (isset($metaData[$key])) {
                        $metaData[$key] = ''; // @todo move this to a customization
                    }
                }

                $config = [
                    'email_template' => $emailTemplate,
                    'push_template' => $pushTemplate,
                    'app' => $app,
                    'company' => $message->company,
                    'message' => sprintf($notificationMessage, $interactionType, $userInteraction->user->displayname),
                    'title' => $notificationTitle,
                    'metadata' => $metaData,
                    'interaction_type' => $interactionType,
                    'interaction' => $interaction,
                    'subject' => sprintf($subject, $message->user->displayname),
                    'via' => $endViaList,
                    'message_owner_id' => $message->user->getId(),
                    'from_user_id' => $userInteraction->user->getId(),
                    'fromUser' => $userInteraction->user,
                    'message_id' => $message->getId(),
                    'parent_message_id' => $message->parent ? $message->parent->getId() : $message->getId(),
                    'destination_id' => $message->getId(),
                    'destination_type' => $params['destination_type'] ?? 'MESSAGE',
                    'destination_event' => $params['destination_event'] ?? 'NEW_MESSAGE',
                ];

                try {
                    $newMessageNotification = new MessageInteractionNotification(
                        $message,
                        $config,
                        $config['via']
                    );
                    $newMessageNotification->setFromUser($userInteraction->user);

                    $message->user->notify($newMessageNotification);
                } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
                    return [
                        'result' => false,
                        'message' => 'Error in notification to user',
                        'exception' => $e,
                    ];
                }

                return [
                    'result' => true,
                    'message' => 'Interaction Notification sent to message owner',
                    'data' => $config,
                    'message_id' => $message->getId(),
                ];
            },
            company: $message->company,
        );
    }
}
