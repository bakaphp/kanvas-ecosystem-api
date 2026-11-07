<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Models\BaseModel;

class CustomerTaxExemption extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use UuidTrait;

    protected $table = 'customer_tax_exemptions';
    protected $guarded = [];

    public static function activeForPeople(Apps $app, ?int $peopleId, ?string $jurisdictionCountry = null): ?self
    {
        if ($peopleId === null) {
            return null;
        }

        $today = Carbon::today()->toDateString();

        return self::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('people_id', $peopleId)
            ->when($jurisdictionCountry !== null, fn ($q) => $q->where('jurisdiction_country', $jurisdictionCountry))
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $today))
            ->first();
    }
}
