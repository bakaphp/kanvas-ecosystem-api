<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsurancePlanRequirement extends BaseModel
{
    protected $table = 'insurance_plan_requirements';

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InsurancePlan::class, 'insurance_plans_id');
    }
}
