<?php

declare(strict_types=1);

namespace Kanvas\MappersImportersTemplates\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;

/**
 * MapperImportersTemplates Model
 *
 * @property int $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property string $description
 */
class MapperImporterTemplate extends BaseModel
{
    protected $table = 'mappers_importers_templates';

    protected $guarded = [];

    public function attributes(): HasMany
    {
        return $this->hasMany(AttributeMapperImporterTemplate::class, 'importers_templates_id')->whereNull("parent_id");
    }
}