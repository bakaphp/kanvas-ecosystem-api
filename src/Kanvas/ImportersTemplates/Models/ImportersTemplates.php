<?php
declare(strict_types=1);

namespace Kanvas\ImportersTemplates\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;

/**
 * ImportersTemplates Model
 *
 * @property int $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property string $description
 */
class ImportersTemplates extends BaseModel
{

    protected $table = 'importers_templates';

    protected $guarded = [];

    public function attributes() : HasMany
    {
        return $this->hasMany(AttributesImportersTemplates::class)->where('parent_id', 0);
    }
}
