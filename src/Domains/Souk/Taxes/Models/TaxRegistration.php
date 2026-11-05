<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
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

    public static function withJurisdictionLock(
        Apps $app,
        string $country,
        ?string $state,
        callable $callback
    ): mixed {
        $key = 'tax_registration:' . $app->getId() . ':' . strtoupper($country) . ':' . strtoupper($state ?? '');

        return Cache::lock($key, 10)->block(5, $callback);
    }

    public static function assertNoOverlap(
        Apps $app,
        string $country,
        ?string $state,
        ?string $from,
        ?string $to,
        ?int $exceptId = null
    ): void {
        $overlaps = static::query()
            ->fromApp($app)
            ->notDeleted()
            ->forJurisdiction($country, $state)
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->get()
            ->contains(fn (TaxRegistration $registration) => $registration->overlaps($from, $to));

        if ($overlaps) {
            throw new ValidationException(
                'A tax registration for this jurisdiction already exists in the specified date range.'
            );
        }
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
