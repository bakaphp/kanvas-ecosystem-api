<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Models\BaseModel;

/**
 * Model UsersInvite.
 *
 * @property string $invite_hash;
 * @property int $users_id;
 * @property int $apps_id
 * @property string $email
 * @property ?string $firstname
 * @property ?string $lastname
 * @property ?string $description
 * @property ?string $configuration
 */
class AdminInvite extends BaseModel
{
    use HasCustomFields;

    public $table = 'admin_invite';

    protected $guarded = [];

    /**
     * Get the user that owns the AdminInvite.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
