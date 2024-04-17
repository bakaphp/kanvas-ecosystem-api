<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Models;

use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Silber\Bouncer\Database\Ability;

class AbilitiesModules extends BaseModel
{
    protected $guarded = [];
    protected $table = 'abilities_modules';

    /**
     * Get the user that owns the AbilitiesModules
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');

    }

    /**
     * Get the user that owns the AbilitiesModules
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ability(): BelongsTo
    {
        return $this->belongsTo(Ability::class, ['abilities_id', 'scope'], ['id', 'scope']);
    }
}
