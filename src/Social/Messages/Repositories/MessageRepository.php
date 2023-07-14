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

    /**
     * getByAppModuleMessage
     *
     * @param  mixed $systemModuleId
     * @param  mixed $entityId
     * @return Message
     */
    public static function getByAppModuleMessage(string $systemModuleClass, string $entityId): ?Message
    {
        return Message::join('app_module_message', 'messages.id', '=', 'app_module_message.message_id')
            ->where('app_module_message.system_modules', $systemModuleClass)
            ->where('app_module_message.entity_id', $entityId)
            ->orderBy('messages.created_at', 'DESC')
            ->select('messages.*')
            ->first();
    }
}
