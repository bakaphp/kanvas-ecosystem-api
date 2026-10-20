<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Models;

use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;

class InsurancePlan extends BaseModel
{
    use CascadeSoftDeletes;

    protected $table = 'insurance_plans';

    protected $cascadeDeletes = ['coverages', 'exclusions', 'requirements'];

    protected function casts(): array
    {
        return [
            'type' => InsuranceCoverageCategoryEnum::class,
            'status' => InsuranceCatalogStatusEnum::class,
            'sponsored' => 'boolean',
            'base_price' => 'decimal:2',
            'deductible' => 'decimal:2',
            'commission_rate' => 'decimal:2',
        ];
    }

    public function scopeFromInsurerTenant(
        Builder $query,
        mixed $app = null,
        mixed $company = null,
    ): Builder {
        return $query->whereHas(
            'insurer',
            fn (Builder $insurer) => $insurer->fromApp($app)->fromCompany($company)
        );
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'insurers_id');
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(InsuranceCoverage::class, 'insurance_plans_id')->orderBy('sort_order');
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(InsurancePlanExclusion::class, 'insurance_plans_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(InsurancePlanRequirement::class, 'insurance_plans_id');
    }
}
