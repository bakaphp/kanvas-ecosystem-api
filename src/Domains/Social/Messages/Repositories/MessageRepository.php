<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Repositories;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Apps\Models\Apps;

class MessageRepository
{
    /**
     * getById
     */
    public static function getById(int $id, Apps $apps): Message
    {
        return Message::fromApp($apps)
                     ->findOrFail($id);
    }
}
