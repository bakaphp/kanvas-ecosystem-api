<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

class CreateCanalIdAction
{
    public function execute(string $channel, string $id): string
    {
        return match ($channel) {
            'whatsapp' => "$id@s.whatsapp.net"
        };
    }
}
