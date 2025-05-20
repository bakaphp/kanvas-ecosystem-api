<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Exception;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;

class CheckNuggetGenerationCountAction
{
    public function __construct(
        private Message $message,
    ) {
    }

    public function execute(): bool
    {
        $freeGenerationCountCustomField = $this->message->user->getId() . '-nugget-free-generation-count';
        $messageOrder = AppModuleMessage::fromApp($this->message->app->getId())
            ->where('apps_id', $this->message->app->getId())
            ->where('companies_id', $this->message->company->getId())
            ->where('system_modules', Order::class)
            ->where('entity_id', $this->message->getId())
            ->where('is_deleted', 0)
            ->first();
        // $messageOrder = AppModuleMessage::find(121530);

        if ($this->message->get($freeGenerationCountCustomField) > $this->message->app->get('nugget-free-generation-limit') && ! $messageOrder->entity->isCompleted()) {
            throw new Exception('You have reached the limit of nuggets you can generate for free');
        }

        ! $this->message->get($freeGenerationCountCustomField) ?
            $this->message->set($freeGenerationCountCustomField, 0) :
            $this->message->increment($freeGenerationCountCustomField);

        return true;
    }
}
