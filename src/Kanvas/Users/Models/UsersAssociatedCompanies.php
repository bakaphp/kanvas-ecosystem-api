<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Kanvas\Models\BaseModel;

/**
 * UsersAssociatedCompanies Model.
 *
 * @property int $users_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property string $identify_id
 * @property int $user_active
 * @property string $user_role
 * @property string $password
 */
class UsersAssociatedCompanies extends BaseModel
{
    //use HasCompositePrimaryKeyTrait;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users_associated_company';

    protected $fillable = [
        'users_id',
        'companies_id',
        'companies_branches_id',
        'identify_id',
        'user_active',
        'user_role',
    ];

    public function deActive(): bool
    {
        $this->user_active = 0;
        return $this->saveOrFail();
    }

    public function active(): bool
    {
        $this->user_active = 1;
        return $this->saveOrFail();
    }
}
