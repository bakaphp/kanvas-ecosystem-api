<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Messages\Observers\UserMessageObserver;
use Kanvas\Social\Models\BaseModel;

/**
 *  Class UserMessage
 *  @property int $message_id
 *  @property int $users_id
 *  @property int $apps_id
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
#[ObservedBy([UserMessageObserver::class])]
class UserMessage extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use HasCompositePrimaryKeyTrait;

    protected $table = 'user_messages';

    protected $guarded = [];

    protected $primaryKey = ['messages_id', 'users_id'];

    public const UPDATED_AT = null;

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
