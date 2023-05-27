<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Users\Models\Users;

class UserMessage extends BaseModel
{
    protected $table = 'user_messages';

    protected $guarded = [];

    const UPDATED_AT = null;
    
    /**
     * user
     */
    public function user(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Users::class, 'users_id');
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
