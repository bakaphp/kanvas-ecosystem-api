<?php

declare(strict_types=1);

namespace Kanvas\ContentEngine\Reviews\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\ContentEngine\Models\BaseModel;

/**
 * @property int $id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int $review_types_id
 * @property ?string $link
 * @property ?string $config
 */
class CompanyReviewType extends BaseModel
{
    protected $table = 'companies_review_types';
    protected $guarded = [];

    protected $casts = [
        'config' => Json::class,
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(ReviewType::class, 'review_types_id', 'id');
    }

    /**
     * scopeCompany.
     *
     * @param mixed $company
     */
    public function scopeFromCompany(Builder $query, mixed $company = null): Builder
    {
        $company = $company instanceof Companies ? $company : auth()->user()->getCurrentCompany();

        return $query->where('companies_id', $company->getId());
    }
}
