<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\RateCards;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kanvas\Souk\Shipping\Contracts\ShippingRateProviderInterface;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Kanvas\Souk\Shipping\RateCards\Models\RateCard;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardCountry;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardRate;
use Kanvas\Workflow\Enums\IntegrationsEnum;

class RateCardShippingProvider implements ShippingRateProviderInterface
{
    public function __construct(
        private readonly string $providerKey,
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    public function name(): string
    {
        return $this->providerKey;
    }

    public function integration(): ?IntegrationsEnum
    {
        return null;
    }

    public function supports(ShipmentRequest $request): bool
    {
        return $this->quote($request) !== [];
    }

    public function quote(ShipmentRequest $request): array
    {
        $totalWeight = $request->totalWeight();

        if ($totalWeight <= 0) {
            return [];
        }

        $zone = $this->resolveZone($request->destinationCountry->code);

        if ($zone === null) {
            return [];
        }

        $quotes = [];

        $cards = RateCard::query()
            ->ownedBy($this->app, $this->company)
            ->notDeleted()
            ->where('provider', $this->providerKey)
            ->with(['rates' => fn (Relation $query) => $query
                ->notDeleted()
                ->where('zone', $zone)
                ->where('max_weight', '>=', $totalWeight)
                ->orderBy('max_weight')])
            ->get();

        foreach ($cards as $card) {
            $rate = $card->rates->first();

            if ($rate !== null) {
                $quotes[] = $this->buildQuote($card, $rate, $zone);
            }
        }

        return $quotes;
    }

    private function resolveZone(string $countryCode): ?string
    {
        return RateCardCountry::query()
            ->ownedBy($this->app, $this->company)
            ->notDeleted()
            ->where('provider', $this->providerKey)
            ->where('country_code', RateCardCountry::normalizeCode($countryCode))
            ->value('zone');
    }

    private function buildQuote(RateCard $card, RateCardRate $rate, string $zone): ShippingQuote
    {
        return new ShippingQuote(
            provider: $this->providerKey,
            serviceCode: $card->service_code,
            serviceName: $card->name,
            amount: round((float) $rate->amount + (float) $card->fixed_charge, 2),
            currency: $card->currency,
            transitMinDays: $rate->transit_min_days,
            transitMaxDays: $rate->transit_max_days,
            meta: [
                'zone' => $zone,
                'max_weight' => (float) $rate->max_weight,
                'weight_unit' => $card->weight_unit,
            ],
        );
    }
}
