<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Actions;

use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;

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
        return MessageType::firstOrCreate(
            [
                'verb' => $this->messageTypeInput->verb,
                'apps_id' => $this->messageTypeInput->apps_id,
                'languages_id' => $this->messageTypeInput->languages_id,
            ],
            [
            'apps_id' => $this->messageTypeInput->apps_id,
            'languages_id' => $this->messageTypeInput->languages_id,
            'name' => $this->messageTypeInput->name,
            'verb' => $this->messageTypeInput->verb,
            'template' => $this->messageTypeInput->template,
            'templates_plura' => $this->messageTypeInput->templates_plura,
        ]
        );
    }
}
