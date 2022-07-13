<?php

declare(strict_types=1);

namespace Kanvas\Sessions\Keys\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Users\Users\Models\Users;
use Kanvas\Sessions\Sessions\Models\Sessions;

/**
 * Apps Model
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
     * Sessions relationship
     *
     * @return Sessions
     */
    public function session(): Sessions
    {
        return $this->belongsTo(Sessions::class, 'sessions_id');
    }

    /**
     * Users relationship
     *
     * @return Users
     */
    public function user(): Users
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
