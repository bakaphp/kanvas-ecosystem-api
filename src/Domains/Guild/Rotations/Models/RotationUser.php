<?php

declare(strict_types=1);

namespace Kanvas\Guild\Rotations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class RotationUser
 * @deprecated version 2.0
 * @property int $id
 * @property int $rotations_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property int $hits
 * @property float $percentage
 */
class RotationUser extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'rotation_users';
    protected $guarded = [];
}
