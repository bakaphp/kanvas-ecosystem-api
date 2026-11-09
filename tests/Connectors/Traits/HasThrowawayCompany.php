<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

/**
 * Connector settings are written to Redis, which no transaction rolls back, so a connector test configures a
 * company of its own and never the shared test company.
 */
trait HasThrowawayCompany
{
    protected function freshCompany(): Companies
    {
        /** @var Companies $company */
        $company = Companies::factory()->create([
            'users_id' => Auth::id(),
            'is_active' => 1,
        ]);
        $company->associateApp(app(Apps::class));

        return $company;
    }
}
