<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

class CreateChannelSlugAction
{
    public function execute(string $channel, string $id): string
    {
        return match ($channel) {
            'whatsapp' => "wa-chat-$id-at-swhatsappnet"
        };
    }
}
