<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class LogContactOptOutChangeActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        /** @var Contact $entity */
        $people = $entity->people;
        if (! $people) {
            return ['result' => false, 'message' => 'Contact has no people'];
        }

        $lead = $people->leads()->notDeleted()->first();
        if (! $lead) {
            return ['result' => false, 'message' => 'People has no lead'];
        }

        $notesChannel = $lead->systemNotes;
        if (! $notesChannel) {
            return ['result' => false, 'message' => 'Lead has no systemNotes channel'];
        }

        $isOptOut = (int) ($params['is_opt_out'] ?? $entity->is_opt_out);
        $content = $isOptOut === 1
            ? 'Do Not Text enabled'
            : 'Do Not Text disabled';

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $lead->app->getId(),
                languages_id: 1,
                name: 'ai-control',
                verb: 'ai-control',
                template: '{{message}}',
                templates_plura: '{{message}}',
            )
        )->execute();

        $createMessageAction = new CreateMessageAction(
            new MessageInput(
                app: $lead->app,
                company: $lead->company,
                user: $lead->user,
                type: $messageType,
                message: [
                    'content' => $content,
                    'from_me' => true,
                ],
            )
        );
        $createMessageAction->runWorkflow = true;
        $message = $createMessageAction->execute();
        $notesChannel->addMessage($message, $lead->user);

        return [
            'result' => true,
            'message' => $content,
            'lead_id' => $lead->getId(),
            'message_id' => $message->getId(),
        ];
    }
}
