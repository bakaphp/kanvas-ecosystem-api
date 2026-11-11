<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Models\BaseModel;
use Override;

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

    public const string CONSOLE_CUSTOM_FIELD = 'console';

    protected $guarded = [];

    /**
     * Set by console.<domain> on inviteAdmin (custom field `console`), so the invite email can send the
     * recipient to the console instead of the app frontend.
     */
    public function isForConsole(): bool
    {
        return filter_var($this->get(self::CONSOLE_CUSTOM_FIELD), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the user that owns the AdminInvite.
     */
    #[Override]
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
