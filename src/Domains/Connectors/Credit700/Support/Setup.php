<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;

class Setup
{
    public function __construct(
        protected AppInterface $app
    ) {
    }

    public function run(): void
    {
        $messageTypeDto = MessageTypeInput::from([
            'apps_id' => $this->app->getId(),
            'name' => ConfigurationEnum::ACTION_VERB->value,
            'verb' => ConfigurationEnum::ACTION_VERB->value,
        ]);
        (new CreateMessageTypeAction($messageTypeDto))->execute();
    }
}
