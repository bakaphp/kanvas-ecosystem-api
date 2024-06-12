<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Tags\Traits\HasTagsTrait;

/**
 * Class Organization.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property ?string $address = null
 */
class Organization extends BaseModel
{
    use UuidTrait;
    use HasTagsTrait;

    protected $table = 'organizations';
    protected $guarded = [];

    public function peoples(): HasManyThrough
    {
        return $this->hasManyThrough(
            People::class,
            OrganizationPeople::class,
            'organizations_id',
            'id',
            'id',
            'peoples_id'
        );
    }

    public function relationships(): HasManyThrough
    {
        return $this->hasManyThrough(
            OrganizationRelated::class,
            OrganizationRelationshipType::class,
            'organizations_id',
            'id',
            'id',
            'organizations_relations_type_id'
        );
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function addPeople(People $people): OrganizationPeople
    {
        return OrganizationPeople::firstOrCreate([
            'organizations_id' => $this->getId(),
            'peoples_id' => $people->getId(),
        ], [
            'created_at' => date('Y-m-d H:i:s'),

        ]);
    }
}
