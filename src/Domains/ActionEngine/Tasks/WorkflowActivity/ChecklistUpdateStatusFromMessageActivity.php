<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\WorkflowActivity;

use Baka\Contracts\AppInterface;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Checklist Update Status From Message',
    description: 'Advances a checklist item when a message shows the step is done. Updates the checklist only.',
)]
class ChecklistUpdateStatusFromMessageActivity extends KanvasActivity
{
    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Message $message, AppInterface $app) use ($params) {
                /** @var array<string, mixed> $messagePayload */
                $messagePayload = is_array($message->message) ? $message->message : [];

                $expectedStatus = $params['expected_message_status'] ?? 'submitted';
                $messageStatus = $messagePayload['status'] ?? $messagePayload['engagement_status'] ?? null;

                if ($expectedStatus !== null && $expectedStatus !== '' && $messageStatus !== $expectedStatus) {
                    return [
                        'message' => "Message status '" . (string) $messageStatus . "' does not match expected '" . (string) $expectedStatus . "'",
                        'result' => false,
                    ];
                }

                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return [
                        'message' => 'Message is not linked to a Lead entity',
                        'result' => false,
                    ];
                }

                /** @var Users $messageUser */
                $messageUser = $message->user;

                return new ProcessMessageTaskUpdatesAction(
                    message: $message,
                    lead: $lead,
                    user: $messageUser,
                )->execute();
            },
            company: $message->company
        );
    }
}
