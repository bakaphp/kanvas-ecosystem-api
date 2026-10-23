<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Models;

use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;

class Insurer extends BaseModel
{
    use CascadeSoftDeletes;

    protected $table = 'insurers';

    protected $cascadeDeletes = ['plans'];

    protected function casts(): array
    {
        return [
            'status' => InsuranceCatalogStatusEnum::class,
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(InsurancePlan::class, 'insurers_id');
    }
}
