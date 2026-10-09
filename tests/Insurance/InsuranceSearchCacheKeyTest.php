<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Kanvas\Insurance\Services\InsuranceSearchCacheKey;
use PHPUnit\Framework\TestCase;

class InsuranceSearchCacheKeyTest extends TestCase
{
    public function testKeyIsStableForTheSameTenantPlanAndVehicle(): void
    {
        $this->assertSame(
            InsuranceSearchCacheKey::make(1, 10, 5, 'sig'),
            InsuranceSearchCacheKey::make(1, 10, 5, 'sig')
        );
    }

    public function testTenantPlanAndVehicleEachChangeTheKey(): void
    {
        $base = InsuranceSearchCacheKey::make(1, 10, 5, 'sig');

        $this->assertNotSame($base, InsuranceSearchCacheKey::make(2, 10, 5, 'sig'));
        $this->assertNotSame($base, InsuranceSearchCacheKey::make(1, 11, 5, 'sig'));
        $this->assertNotSame($base, InsuranceSearchCacheKey::make(1, 10, 6, 'sig'));
        $this->assertNotSame($base, InsuranceSearchCacheKey::make(1, 10, 5, 'other'));
    }
}
