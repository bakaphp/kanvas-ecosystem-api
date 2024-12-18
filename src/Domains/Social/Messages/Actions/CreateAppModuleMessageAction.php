<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Social\Messages\Models\AppModuleMessage;

class CreateAppModuleMessageAction
{
    public function __construct(
        public Message $message,
        public ?SystemModules $systemModule = null,
        public mixed $entityId = null,
    ) {
    }

    public function execute(): AppModuleMessage
    {
        return AppModuleMessage::firstOrCreate([
            'message_id' => $this->message->id,
            'message_types_id' => $this->message->message_types_id,
            'apps_id' => $this->message->app->getId(),
            'companies_id' => $this->message->companies_id,
            'system_modules' => $this->systemModule->model_name,
            'entity_id' => $this->entityId,
        ]);
    }
}
