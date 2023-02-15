<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Kanvas\Guild\Models\BaseModel;

/**
 * Class ContactTypes.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $icon
 *
 */
class ContactTypes extends BaseModel
{
    protected $table = 'contacts_types';
    protected $guarded = [];
}
