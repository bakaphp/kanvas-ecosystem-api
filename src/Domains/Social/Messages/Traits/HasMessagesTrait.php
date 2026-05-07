<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Traits;

use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;

trait HasMessagesTrait
{
    public function getIntegrationMessage(): HasManyThrough
    {
        //@todo replace table name for getTableName method.
        return $this->hasManyThrough(
            Message::class,
            AppModuleMessage::class,
            'entity_id',
            'id',
            'id',
            'message_id'
        )->where('app_module_message.system_modules', static::class);
    }
}
