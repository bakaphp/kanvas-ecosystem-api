<?php
declare(strict_types=1);

namespace Kanvas\ImportersTemplates\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;

/**
 * class AttributesImportersTemplates
 * @property int $id
 * @property int $importers_templates_id
 * @property int $parent_id
 * @property string $name
 * @property string $value
 *  
 * */
class AttributesImportersTemplates extends BaseModel
{

    protected $table = 'attributes_importers_templates';

    protected $guarded = [];

    public function parent() : BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children() : HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function importersTemplates() : BelongsTo
    {
        return $this->belongsTo(ImportersTemplates::class, 'importers_templates_id');
    }
}
