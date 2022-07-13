<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Companies\Branches\Factories\CompaniesBranchesFactory;
use Kanvas\Users\Users\Models\Users;
use Kanvas\Companies\Companies\Models\Companies;

/**
 * Companies Model
 *
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $address
 * @property string $email
 * @property string $phone
 * @property string $zipcode
 * @property int $is_default
 */
class CompaniesBranches extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies_branches';

    /**
    * Create a new factory instance for the model.
    *
    * @return \Illuminate\Database\Eloquent\Factories\Factory
    */
    protected static function newFactory()
    {
        return CompaniesBranchesFactory::new();
    }

    /**
     * Companies relationship
     *
     * @return Companies
     */
    public function company(): Companies
    {
        return $this->belongsTo(Companies::class, 'companies_id');
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
