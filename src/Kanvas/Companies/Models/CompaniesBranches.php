<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Branches\Factories\CompaniesBranchesFactory;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Companies Model.
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
     * Companies relationship.
     *
     * @return BelongsTo
     */
    public function company() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
