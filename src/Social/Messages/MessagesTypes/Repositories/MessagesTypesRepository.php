<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\MessagesTypes\Repositories;

use Kanvas\Social\Messages\MessagesTypes\Models\MessageType;

class MessagesTypesRepository
{
    /**
     * getById
     */
    public static function getById(int $id): MessageType
    {
        return MessageType::findOrFail($id);
    }

    /**
     * getByUuid
     */
    public static function getByUuid(string $uuid): MessageType
    {
        return MessageType::where('uuid', $uuid)
               ->firstOrFail();
    }
}
