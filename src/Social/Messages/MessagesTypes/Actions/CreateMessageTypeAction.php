<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\MessagesTypes\Actions;

use Kanvas\Social\Messages\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\Messages\MessagesTypes\Models\MessageType;

class CreateMessageTypeAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        protected MessageTypeInput $messageTypeInput
    ) {
    }

    /**
     * execute
     */
    public function execute(): MessageType
    {
        return MessageType::create([
            'apps_id' => $this->messageTypeInput->apps_id,
            'languages_id' => $this->messageTypeInput->languages_id,
            'name' => $this->messageTypeInput->name,
            'verb' => $this->messageTypeInput->verb,
            'template' => $this->messageTypeInput->template,
            'templates_plura' => $this->messageTypeInput->templates_plura,
        ]);
    }
}
