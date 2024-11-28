<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Baka\Contracts\AppInterface;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\SoftDeletesTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
    use SoftDeletesTrait;

    protected $table = 'user_messages';

    protected $guarded = [];

    protected $primaryKey = ['apps_id', 'messages_id', 'users_id'];

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

    public static function getUserFeed(UserInterface $user, AppInterface $app): EloquentBuilder
    {
        return Message::query()
                ->join('user_messages', 'messages.id', '=', 'user_messages.messages_id')
                ->where('user_messages.users_id', $user->getId())
                ->where('user_messages.apps_id', $app->getId())
                ->orderBy('user_messages.created_at', 'desc') //for now always order by created_at in the user feed
                ->select('messages.*');
    }

    public static function getFirstMessageFromPage(UserInterface $user, AppInterface $app, int $pageNumber, int $limit = 25): ?UserMessage
    {
        $offset = ($pageNumber - 1) * $limit;

        return self::fromApp($app)
            ->where('users_id', $user->getId())
            ->notDeleted()
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take(1)
            ->first();
    }
}
