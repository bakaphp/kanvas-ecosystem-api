<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Shipping;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Shipping\RateCards\Models\RateCard;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardRate;
use Tests\TestCase;

final class RateCardCascadeSoftDeleteTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'commerce'];

    public function testDeletingARateCardCascadesSoftDeleteToItsRates(): void
    {
        $rateCard = RateCard::create([
            'companies_id' => Companies::factory()->create()->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'provider' => 'inposdom',
            'service_code' => 'ems',
            'name' => 'EMS',
            'currency' => 'DOP',
        ]);
        $rates = [
            RateCardRate::create(['rate_card_id' => $rateCard->getId(), 'zone' => 'z1', 'max_grams' => 500, 'amount' => 850]),
            RateCardRate::create(['rate_card_id' => $rateCard->getId(), 'zone' => 'z1', 'max_grams' => 1000, 'amount' => 1200]),
        ];

        $rateCard->delete();

        $this->assertTrue($rateCard->trashed());
        $this->assertSame(0, RateCard::query()->whereKey($rateCard->getId())->count());
        $this->assertSame(0, RateCardRate::query()->where('rate_card_id', $rateCard->getId())->count());
        $this->assertSame(
            2,
            DB::connection('commerce')
                ->table('shipping_rate_card_rates')
                ->whereIn('id', array_map(fn (RateCardRate $rate) => $rate->getId(), $rates))
                ->where('is_deleted', 1)
                ->count()
        );
    }
}
