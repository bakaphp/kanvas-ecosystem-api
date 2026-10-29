<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Communication\Models\BaseModel;

/**
 * @property int $id
 * @property int $registration_id
 * @property int $connection_id
 */
class SharedDependent extends BaseModel
{
    protected $table = 'communication_shared_dependents';

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(SharedRegistration::class, 'registration_id');
    }
}
