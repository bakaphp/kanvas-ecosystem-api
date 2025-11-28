<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Models;

use Baka\Traits\PublicAppScopeTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Models\BaseModel;

/**
 *  class Channels.
 *  @package Kanvas\Social\Channels\Models
 *  @property int $id
 *  @property string $name
 *  @property string $slug
 *  @property string $description
 *  @property int $last_message_id
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int|null $entity_id
 *  @property string|null $entity_namespace
 */
class ChannelCategories extends BaseModel
{
    use PublicAppScopeTrait;

    protected $table = 'channel_categories';

    protected $fillable = [
        'name',
        'apps_id',
        'companies_id',
    ];

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'category_id');
    }
}
