<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Social\Messages\Models\Message;

/**
 * class AppModuleMessage
 * @property int $message_id
 * @property int $message_types_id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $system_modules
 * @property int $entity_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class AppModuleMessage extends BaseModel
{
    protected $table = 'app_module_message';

    protected $guarded = [];

    /**
     * messageType
     */
    public function messageType(): BelongsTo
    {
        return $this->belongsTo(MessageType::class, 'message_types_id');
    }

    /**
     * app
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * company
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * message
     */
    public function message(): BelongsTo
    {
        return $this->belongTo(Message::class, 'message_id');
    }
}
