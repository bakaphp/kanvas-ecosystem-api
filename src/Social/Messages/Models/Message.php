<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Laravel\Scout\Searchable;

/**
 *  Class Message
 *  @property int $id
 *  @property int $parent_id
 *  @property string $parent_unique_id
 *  @property string $uuid
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int $users_id
 *  @property int $message_types_id
 *  @property string $message
 *  @property int $reactions_count
 *  @property int $comments_count
 *  @property int $total_liked
 *  @property int $total_saved
 *  @property int $total_shared
 */
class Message extends BaseModel
{
    use UuidTrait;
    use Searchable;

    protected $table = 'messages';

    protected $guarded = [
        'uuid',
    ];

    protected $casts = [
        'message' => Json::class,
    ];

    /**
      * Get the name of the index associated with the model.
      */
    public function searchableAs(): string
    {
        return 'messages_index_app_' . $this->apps_id;
    }

    /**
     * parent
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id', 'id');
    }

    /**
     * app
     */
    public function app(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * company
     */
    public function company(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * user
     */
    public function user(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Users::class, 'users_id');
    }

    /**
     * messageType
     */
    public function messageType(): BelongsTo
    {
        return $this->belongsTo(MessageType::class, 'message_types_id');
    }

    /**
     * appModuleMessage
     */
    public function appModuleMessage(): HasOne
    {
        return $this->setConnection('ecosystem')->hasOne(AppModuleMessage::class, 'message_id');
    }
}
