<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessageTypeService
{
    public static function getOrCreate(
        AppInterface $app,
        string $verb,
        ?string $name = null,
        ?string $template = null,
        mixed $templatesPlura = null,
        int $languagesId = 0,
    ): MessageType {
        $messageTypeInput = MessageTypeInput::from([
            'apps_id' => $app->getId(),
            'verb' => $verb,
            'name' => $name ?? Str::title($verb),
            'template' => $template,
            'templates_plura' => $templatesPlura,
            'languages_id' => $languagesId,
        ]);

        return new CreateMessageTypeAction($messageTypeInput)->execute();
    }
}
