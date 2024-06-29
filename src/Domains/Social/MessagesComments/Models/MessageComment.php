<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesComments\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\AccessControlList\Traits\HasPermissions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Models\BaseModel;
use Nevadskiy\Tree\AsTree;

/**
 *  class MessageComment.
 *  @property int $id
 *  @property int $message_id
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int $users_id
 *  @property string $message
 *  @property int $reactions_count
 */
class MessageComment extends BaseModel
{
    use AsTree;
    use HasPermissions;
    
    protected $table = 'message_comments';

    protected $guarded = [];

    protected $casts = [
       'message' => Json::class,
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function getCommentAttribute()
    {
        return $this->attributes['message'];
    }
}
