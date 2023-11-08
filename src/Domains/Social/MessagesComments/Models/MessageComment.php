<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesComments\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Models\BaseModel;

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
    protected $table = 'message_comments';

    protected $guarded = [];

    public function messages(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
