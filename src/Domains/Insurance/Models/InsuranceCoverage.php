<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceCoverage extends BaseModel
{
    protected $table = 'insurance_coverages';

    protected function casts(): array
    {
        return [
            'limit_amount' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InsurancePlan::class, 'insurance_plans_id');
    }
}
