<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Souk\Models\BaseModel;

class TaxRegistration extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use UuidTrait;

    protected $table = 'tax_registrations';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => Json::class,
        ];
    }

    public function scopeForJurisdiction(Builder $query, string $country, ?string $state): Builder
    {
        return $query
            ->where('jurisdiction_country', $country)
            ->where('jurisdiction_state', $state);
    }

    /**
     * Open-ended registrations (null from/to) overlap everything on that side.
     */
    public function overlaps(?string $from, ?string $to): bool
    {
        $existingStart = $this->effective_from ?? '0000-01-01';
        $existingEnd = $this->effective_to ?? '9999-12-31';
        $newStart = $from ?? '0000-01-01';
        $newEnd = $to ?? '9999-12-31';

        return $existingStart <= $newEnd && $existingEnd >= $newStart;
    }
}
