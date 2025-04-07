<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Observers\UsersAssociatedCompaniesObserver;

/**
 * UserCompanyApps Model.
 *
 * @property int $companies_id
 * @property int $apps_id
 */
#[ObservedBy([UsersAssociatedCompaniesObserver::class])]
class UserCompanyApps extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_company_apps';

    protected $primaryKey = ['companies_id', 'apps_id'];

    protected $fillable = [
        'companies_id',
        'apps_id',
    ];

    public $incrementing = false;

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
