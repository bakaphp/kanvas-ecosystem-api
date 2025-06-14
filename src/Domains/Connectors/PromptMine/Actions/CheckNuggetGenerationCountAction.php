<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Exception;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Souk\Orders\Models\Order;

class CheckNuggetGenerationCountAction
{
    public function __construct(
        private Message $message,
    ) {
    }

    public function execute(): bool
    {
        //$loggedUsed = auth()->user();
        $messageType = MessageType::findOrFail($this->message->app->get('free-generation-check-message-type'));
        $messageOrder = AppModuleMessage::fromApp($this->message->app->getId())
            ->where('apps_id', $this->message->app->getId())
            ->where('companies_id', $this->message->company->getId())
            ->where('message_types_id', $messageType->getId())
            ->where('system_modules', Order::class)
            ->where('entity_id', $this->message->getId())
            ->where('is_deleted', 0)
            ->first();

        if (($this->message->parent->total_children > $this->message->app->get('nugget-free-generation-limit'))
            || ($messageOrder && ! $messageOrder->entity->isCompleted())) {
            throw new Exception('You have reached the limit of nuggets you can generate for free');
        }

        return true;
    }
}
