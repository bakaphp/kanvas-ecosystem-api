<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\Communication\DeliverConversationMessageAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Leads Deliver Human Reply',
    description: 'A reply a person wrote in a lead conversation goes to the customer on the medium the '
        . 'conversation is in, and the person takes the lead over (runs on the channel updated event, '
        . 'param message, condition from_human)',
    requiredParams: ['message'],
)]
class DeliverHumanReplyActivity extends KanvasActivity
{
    // A retry after the send would message the customer twice.
    public $tries = 1;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        if (! $entity instanceof Channel || ! $message instanceof Message || ! $message->isFromHuman()) {
            return $this->skipWorkflow('Not a person\'s message');
        }

        // On the default and internal channels a person writes without talking to the customer.
        if ($entity->isDefaultChannel() || $entity->isInternalChannel()) {
            return $this->skipWorkflow('Written on a channel that never reaches the customer');
        }

        $delivery = new DeliverConversationMessageAction($entity, $message, writtenByPerson: true)->execute();

        if ($delivery['failed']) {
            return $this->failWorkflow($delivery);
        }

        return $delivery['delivered'] ? $delivery : $this->skipWorkflow($delivery['message'], $delivery);
    }
}
