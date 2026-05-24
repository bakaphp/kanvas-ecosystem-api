<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * For Intelligence tables without an is_deleted column. The rich
 * Intelligence\Models\BaseModel can't be used — it pulls in SoftDeletesTrait
 * and a $attributes['is_deleted' => 0] default that fails inserts here.
 *
 * KanvasModelTrait's getById*() helpers assume is_deleted exists, so use
 * ::query()->fromApp() / ->fromCompany() for lookups instead.
 *
 * Twin of NervousSystem\Models\ImmutableBaseModel.
 */
class ImmutableBaseModel extends Model
{
    use HasFactory;
    use KanvasModelTrait;

    protected $connection = 'intelligence';
}
