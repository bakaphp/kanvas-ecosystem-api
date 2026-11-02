<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceVehicleModelMap extends BaseModel
{
    protected $table = 'insurance_vehicle_model_map';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'year' => 'integer',
        ];
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'insurers_id');
    }
}
