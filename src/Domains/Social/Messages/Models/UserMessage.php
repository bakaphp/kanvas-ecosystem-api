<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 *  Class UserMessage
 *  @property int $message_id
 *  @property int $users_id
 *  @property int $is_liked
 *  @property int $is_disliked
 *  @property int $is_saved
 *  @property int $is_shared
 *  @property int $is_reported
 *  @property string $notes
 *  @property string $reactions
 *  @property string $saved_lists
 *  @property string $activities
 */
class UserMessage extends BaseModel
{
    protected $table = 'user_messages';

    protected $guarded = [];

    public const UPDATED_AT = null;

    /**
     * user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * message
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'messages_id');
    }

    /**
     * Get all of the activities for the UserMessage
     */
    public function activities(): HasMany
    {
        return $this->hasMany(UserMessageActivity::class, 'user_messages_id');
    }
}
