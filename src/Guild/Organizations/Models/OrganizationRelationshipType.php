<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class OrganizationRelationshipType.
 *
 * @property int $id
 * @property int $name
 */
class OrganizationRelationshipType extends BaseModel
{
    use UuidTrait;
    use NoAppRelationshipTrait;

    protected $table = 'organizations_relations_type';
    protected $guarded = [];
}
