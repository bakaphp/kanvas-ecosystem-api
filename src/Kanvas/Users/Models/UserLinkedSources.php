<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * User Linked Sources Model.
 *
 * @property string $title
 * @property string $url
 * @property int $language_id
 */
class UserLinkedSources extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_linked_sources';

    protected $fillable = [
        'users_id',
        'source_id',
        'source_users_id',
        'source_users_id_text',
        'source_username',
    ];

    /**
     * Users relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Sources::class, 'source_id');
    }

    /**
     * Create user link source based on social provider
     */
    public static function createSocial(SocialiteUser $socialUser, Users $user, Sources $source): self
    {
        $linked = new self();
        $linked->users_id = $user->id;
        $linked->source_id = $source->id;
        $linked->source_users_id = $socialUser->id;
        $linked->source_users_id_text = $socialUser->token;
        $linked->source_username = $socialUser->nickname ?? $socialUser->name;
        $linked->saveOrFail();

        return $linked;
    }
}
