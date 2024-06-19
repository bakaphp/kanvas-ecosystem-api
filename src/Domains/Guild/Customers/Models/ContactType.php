<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Contracts\AppInterface;
use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class ContactTypes.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $icon
 */
class ContactType extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'contacts_types';
    protected $guarded = [];

    public static function getByName(string $name, ?AppInterface $app = null): self
    {
        return self::firstOrCreate([
            'name' => $name,
            'companies_id' => 0,
            'users_id' => 1
        ]);
    }
}
