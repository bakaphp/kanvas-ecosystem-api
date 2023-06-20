<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Repositories;

use Kanvas\Social\Messages\Models\Message;

class MessageRepository
{
    /**
     * getById
     */
    public static function getById(int $id): Message
    {
        return Message::findOrFail($id);
    }
}
