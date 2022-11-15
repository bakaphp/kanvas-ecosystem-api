<?php
declare(strict_types=1);
namespace Inventory\Regions\Models;

use Inventory\Models\BaseModel;

/**
 * Class Regions.
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $currency_id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $short_slug
 * @property ?string settings = null
 * @property int $is_default
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class Regions extends BaseModel
{
    protected $table = 'regions';
    protected $guarded = [];
}
