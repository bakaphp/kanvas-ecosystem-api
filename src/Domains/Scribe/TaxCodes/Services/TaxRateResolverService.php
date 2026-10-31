<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\Services;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;
use Kanvas\Scribe\TaxCodes\Models\TaxRate;

class TaxRateResolverService
{
    public function findByCode(string $code, Apps $app): ?TaxCode
    {
        return TaxCode::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    public function activeRate(TaxCode $taxCode, ?Carbon $at = null): ?TaxRate
    {
        return $taxCode->activeRate($at);
    }

    public function resolveRate(string $code, Apps $app, ?Carbon $at = null): ?TaxRate
    {
        $taxCode = $this->findByCode($code, $app);

        if ($taxCode === null) {
            return null;
        }

        return $this->activeRate($taxCode, $at);
    }
}
