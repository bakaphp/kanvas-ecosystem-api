<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * User Linked Sources Model.
 *
 * @property string $title
 * @property string $url
 * @property string $source_users_id
 * @property string $source_users_id_text
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
}
