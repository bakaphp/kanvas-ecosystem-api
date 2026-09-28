<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Database\Seeders\Souk\DiscountTypeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Souk\Discounts\Enums\DiscountTypeEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Tests\TestCase;

final class AutoAppliedCreditDiscountTypeTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DiscountTypeSeeder::class);
    }

    public function testSeederCreatesTheGlobalAutoAppliedCreditType(): void
    {
        $type = DiscountType::getByName(DiscountTypeEnum::AUTO_APPLIED_CREDIT->label());

        $this->assertSame(0, (int) $type->apps_id);
        $this->assertSame('Auto Applied Credit', $type->name);
    }

    public function testDiscountKnowsWhenItIsAnAutoAppliedCredit(): void
    {
        $creditType = DiscountType::getByName(DiscountTypeEnum::AUTO_APPLIED_CREDIT->label());
        $promoType = DiscountType::getByName(DiscountTypeEnum::FIXED_AMOUNT->label());

        $credit = Discount::factory()->create(['discount_type_id' => $creditType->getId()]);
        $promo = Discount::factory()->create(['discount_type_id' => $promoType->getId()]);

        $this->assertTrue($credit->isAutoAppliedCredit());
        $this->assertFalse($promo->isAutoAppliedCredit());
    }
}
