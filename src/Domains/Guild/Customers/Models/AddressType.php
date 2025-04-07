<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class AddressType.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 */
class AddressType extends BaseModel
{
    protected $table = 'address_types';
    protected $guarded = [];

    public static function getByName(string $name, ?AppInterface $app = null): self
    {
        $app = $app ?? app(Apps::class);

        return self::firstOrCreate([
            'name' => $name,
            'companies_id' => 0,
            'apps_id' => $app->getId(),
            'users_id' => 1,
        ]);
    }
}
