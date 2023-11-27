<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessagesTypesRepository
{
    /**
     * getById
     */
    public static function getById(int $id, AppInterface $app): MessageType
    {
        return MessageType::fromApp($app)->findOrFail($id);
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
    public static function getByVerb(string $verb, AppInterface $app): MessageType
    {
        return MessageType::fromApp($app)->where('verb', $verb)
            ->first();
    }
}
