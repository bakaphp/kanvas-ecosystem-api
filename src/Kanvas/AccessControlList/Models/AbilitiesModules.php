<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Silber\Bouncer\Database\Ability;

class AbilitiesModules extends BaseModel
{
    protected $guarded = [];
    protected $table = 'abilities_modules';

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    public function ability(): BelongsTo
    {
        return $this->belongsTo(Ability::class, ['abilities_id', 'scope'], ['id', 'scope']);
    }
}
