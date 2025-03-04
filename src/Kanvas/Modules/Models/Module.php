<?php

declare(strict_types=1);

namespace Kanvas\Modules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Apps\Models\Apps;

class Module extends BaseModel
{
    protected $table = "modules";

    protected $guarded = [];

    public function systemModules(): BelongsToMany
    {
        $app = app(Apps::class);
        return $this->belongsToMany(
            SystemModules::class,
            'abilities_modules',
            'module_id',
            'system_modules_id'
        )->where('abilities_modules.apps_id', $app->id)
        ->groupBy('system_modules_id');
    }
}
