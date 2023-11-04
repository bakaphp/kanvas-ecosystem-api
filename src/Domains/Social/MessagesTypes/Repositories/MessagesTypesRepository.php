<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Repositories;

use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessagesTypesRepository
{
    /**
     * getById
     */
    public static function getById(int $id): MessageType
    {
        return MessageType::fromApp()->findOrFail($id);
    }

    /**
     * getByUuid
     */
    public static function getByUuid(string $uuid): MessageType
    {
        return MessageType::getByUuid($uuid);
    }

    /**
     * getByUuid
     */
    public static function getByVerb(string $verb): MessageType
    {
        return MessageType::fromApp()->where('verb', $verb)
            ->first();
    }
}
