<?php

declare(strict_types=1);

namespace Kanvas\Sessions\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Apps Model.
 *
 * @property int $sessions_id
 * @property int $users_id
 * @property string $last_ip
 * @property int $last_login
 */
class SessionKeys extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'session_keys';

    /**
     * disable created_At and updated_At.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $attributes = [];

    /**
     * Sessions relationship.
     *
     * @return BelongsTo
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Sessions::class, 'sessions_id');
    }

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
