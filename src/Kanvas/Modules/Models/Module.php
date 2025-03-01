<?php
declare(strict_types=1);

namespace Kanvas\Modules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

class Module extends BaseModel
{

    protected $table = "modules";

    protected $guarded = [];

    public function systemModules(): BelongsToMany
    {
        return $this->belongsToMany(
            SystemModules::class,
            'abilities_modules',
            'module_id',
            'system_modules_id'
        )->groupBy('system_modules_id');
    }

}
