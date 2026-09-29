<?php

declare(strict_types=1);

namespace Tests\Souk\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Shipping\RateCards\Actions\ImportRateCardsAction;

trait ImportsShippingRateCards
{
    protected function importRateCards(Companies $company, array $rateCards, ?Apps $app = null): array
    {
        return new ImportRateCardsAction($app ?? app(Apps::class), $company, $rateCards)->execute();
    }

    protected function inposdomRateCardsFromJson(): array
    {
        return json_decode(
            file_get_contents(database_path('seeders/data/shipping/inposdom/shipping_rate_cards.json')),
            true
        );
    }
}
