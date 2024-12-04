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
        $data = [
            'message_id' => $this->message->getId(),
            'message_types_id' => $this->message->message_types_id,
            'apps_id' => $this->message->app->getId(),
            'companies_id' => $this->message->company->getId(),
            'system_modules' => $this->systemModule->model_name,
            'entity_id' => $this->entityId,
        ];

        return AppModuleMessage::create($data);
    }
}
