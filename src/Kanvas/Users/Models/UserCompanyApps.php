<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * UserCompanyApps Model.
 *
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $stripe_id
 * @property int $subscriptions_id
 */
class UserCompanyApps extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_company_apps';

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function company() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function app() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
