<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\RateCards\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kanvas\Souk\Shipping\RateCards\Models\RateCard;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardCountry;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardRate;

class ImportRateCardsAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly array $rateCards,
    ) {
    }

    public function execute(): array
    {
        $this->assertValidPayload();

        return DB::connection('commerce')->transaction(function (): array {
            $counts = [
                'cards_upserted' => 0,
                'cards_deleted' => 0,
                'rates_upserted' => 0,
                'rates_deleted' => 0,
                'countries_upserted' => 0,
                'countries_deleted' => 0,
            ];

            foreach ($this->rateCards as $provider => $config) {
                $providerCounts = array_merge(
                    $this->syncCards((string) $provider, $config['services']),
                    $this->syncCountries((string) $provider, $config['zones'])
                );

                foreach ($providerCounts as $metric => $count) {
                    $counts[$metric] += $count;
                }
            }

            return $counts;
        });
    }

    private function syncCards(string $provider, array $services): array
    {
        $counts = ['cards_upserted' => 0, 'cards_deleted' => 0, 'rates_upserted' => 0, 'rates_deleted' => 0];
        $existingCards = RateCard::query()
            ->withoutGlobalScopes()
            ->ownedBy($this->app, $this->company)
            ->where('provider', $provider)
            ->get()
            ->keyBy('service_code');

        foreach ($services as $serviceCode => $service) {
            $card = $existingCards->get((string) $serviceCode) ?? new RateCard([
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'provider' => $provider,
                'service_code' => (string) $serviceCode,
            ]);

            $this->persist($card, [
                'name' => $service['name'],
                'currency' => strtoupper(trim($service['currency'])),
                'weight_unit' => strtolower(trim($service['weight_unit'])),
                'fixed_charge' => $service['fixed_charge'] ?? 0,
            ]);
            $counts['cards_upserted']++;

            $rateCounts = $this->syncRates($card, $service['zones']);
            $counts['rates_upserted'] += $rateCounts['upserted'];
            $counts['rates_deleted'] += $rateCounts['deleted'];
        }

        foreach ($existingCards as $serviceCode => $card) {
            if (! array_key_exists($serviceCode, $services) && $this->isLive($card)) {
                $counts['rates_deleted'] += $card->rates()->count();
                $card->delete();
                $counts['cards_deleted']++;
            }
        }

        return $counts;
    }

    private function syncRates(RateCard $card, array $zones): array
    {
        $upserted = 0;
        $deleted = 0;
        $keptKeys = [];
        $existingRates = RateCardRate::query()
            ->withoutGlobalScopes()
            ->where('rate_card_id', $card->getId())
            ->get()
            ->keyBy(fn (RateCardRate $rate) => $this->rateKey($rate->zone, $rate->max_weight));

        foreach ($zones as $zone => $zoneEntry) {
            foreach ($zoneEntry['rates'] as $rate) {
                $key = $this->rateKey((string) $zone, $rate['max_weight']);
                $keptKeys[] = $key;

                $model = $existingRates->get($key) ?? new RateCardRate([
                    'rate_card_id' => $card->getId(),
                    'zone' => (string) $zone,
                    'max_weight' => $rate['max_weight'],
                ]);

                $this->persist($model, [
                    'amount' => $rate['amount'],
                    'transit_min_days' => $zoneEntry['transit_min_days'] ?? null,
                    'transit_max_days' => $zoneEntry['transit_max_days'] ?? null,
                ]);
                $upserted++;
            }
        }

        foreach ($existingRates as $key => $rate) {
            if (! in_array($key, $keptKeys, true) && $this->isLive($rate)) {
                $rate->delete();
                $deleted++;
            }
        }

        return ['upserted' => $upserted, 'deleted' => $deleted];
    }

    private function syncCountries(string $provider, array $zones): array
    {
        $upserted = 0;
        $deleted = 0;
        $keptCodes = [];
        $existingCountries = RateCardCountry::query()
            ->withoutGlobalScopes()
            ->ownedBy($this->app, $this->company)
            ->where('provider', $provider)
            ->get()
            ->keyBy('country_code');

        foreach ($zones as $countryCode => $zone) {
            $code = RateCardCountry::normalizeCode((string) $countryCode);
            $keptCodes[] = $code;

            $country = $existingCountries->get($code) ?? new RateCardCountry([
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'provider' => $provider,
                'country_code' => $code,
            ]);

            $this->persist($country, ['zone' => $zone]);
            $upserted++;
        }

        foreach ($existingCountries as $code => $country) {
            if (! in_array($code, $keptCodes, true) && $this->isLive($country)) {
                $country->delete();
                $deleted++;
            }
        }

        return ['countries_upserted' => $upserted, 'countries_deleted' => $deleted];
    }

    private function persist(Model $model, array $attributes): void
    {
        if ($model->exists && ! $this->isLive($model)) {
            $model->restore();
        }

        $model->fill($attributes)->save();
    }

    private function isLive(Model $model): bool
    {
        return (int) $model->getRawOriginal('is_deleted') === 0;
    }

    private function rateKey(string $zone, float|int|string $maxWeight): string
    {
        return $zone . '|' . number_format((float) $maxWeight, 3, '.', '');
    }

    private function assertValidPayload(): void
    {
        foreach ($this->rateCards as $provider => $config) {
            $this->assertValidProvider($provider, $config);
        }
    }

    private function assertValidProvider(mixed $provider, mixed $config): void
    {
        $this->ensure(
            is_string($provider) && $provider !== '' && strlen($provider) <= 64,
            'Provider keys must be non-empty strings of at most 64 characters.'
        );
        $this->ensure(is_array($config), "Provider '{$provider}' must be an object.");
        $this->ensure(is_array($config['zones'] ?? null), "Provider '{$provider}' must define a 'zones' object.");
        $this->ensure(is_array($config['services'] ?? null), "Provider '{$provider}' must define a 'services' object.");

        $seenCountryCodes = [];

        foreach ($config['zones'] as $countryCode => $zone) {
            $this->ensure(
                preg_match('/^[A-Za-z]{2}$/', (string) $countryCode) === 1,
                "Provider '{$provider}' has an invalid country code '{$countryCode}'."
            );
            $normalizedCode = RateCardCountry::normalizeCode((string) $countryCode);
            $this->ensure(
                ! isset($seenCountryCodes[$normalizedCode]),
                "Provider '{$provider}' lists the country code '{$normalizedCode}' more than once."
            );
            $seenCountryCodes[$normalizedCode] = true;
            $this->assertValidZoneName($zone, "Provider '{$provider}' country '{$countryCode}'");
        }

        foreach ($config['services'] as $serviceCode => $service) {
            $this->assertValidService($provider, (string) $serviceCode, $service);
        }
    }

    private function assertValidService(string $provider, string $serviceCode, mixed $service): void
    {
        $label = "Service '{$provider}.{$serviceCode}'";

        $this->ensure(
            $serviceCode !== '' && strlen($serviceCode) <= 64,
            "{$label} needs a service code of at most 64 characters."
        );
        $this->ensure(is_array($service), "{$label} must be an object.");
        $this->ensure(is_string($service['name'] ?? null) && trim($service['name']) !== '', "{$label} must have a name.");
        $this->ensure(
            is_string($service['currency'] ?? null) && preg_match('/^[A-Za-z]{3}$/', trim($service['currency'])) === 1,
            "{$label} must have a 3-letter currency."
        );
        $this->ensure(
            is_string($service['weight_unit'] ?? null)
                && trim($service['weight_unit']) !== ''
                && strlen(trim($service['weight_unit'])) <= 8,
            "{$label} must have a weight_unit of at most 8 characters."
        );
        $this->ensure(
            ! isset($service['fixed_charge']) || (is_numeric($service['fixed_charge']) && $service['fixed_charge'] >= 0),
            "{$label} has an invalid fixed_charge."
        );
        $this->ensure(is_array($service['zones'] ?? null), "{$label} must define a 'zones' object.");

        foreach ($service['zones'] as $zone => $zoneEntry) {
            $this->assertValidZoneEntry("{$label} zone '{$zone}'", (string) $zone, $zoneEntry);
        }
    }

    private function assertValidZoneEntry(string $label, string $zone, mixed $zoneEntry): void
    {
        $this->assertValidZoneName($zone, $label);
        $this->ensure(is_array($zoneEntry), "{$label} must be an object.");
        $this->ensure(
            is_array($zoneEntry['rates'] ?? null) && $zoneEntry['rates'] !== [],
            "{$label} must have at least one rate."
        );

        foreach (['transit_min_days', 'transit_max_days'] as $field) {
            $days = $zoneEntry[$field] ?? null;
            $this->ensure($days === null || (is_int($days) && $days >= 0 && $days <= 65535), "{$label} has an invalid {$field}.");
        }

        $seenMaxWeights = [];

        foreach ($zoneEntry['rates'] as $rate) {
            $this->ensure(
                is_array($rate)
                    && is_numeric($rate['max_weight'] ?? null)
                    && round((float) $rate['max_weight'], 3) > 0
                    && $rate['max_weight'] < 10000000,
                "{$label} has a rate with an invalid max_weight."
            );
            $this->ensure(
                is_numeric($rate['amount'] ?? null) && $rate['amount'] >= 0,
                "{$label} has a rate with an invalid amount."
            );
            $weightKey = $this->rateKey($zone, $rate['max_weight']);
            $this->ensure(! isset($seenMaxWeights[$weightKey]), "{$label} repeats the max_weight {$rate['max_weight']}.");
            $seenMaxWeights[$weightKey] = true;
        }
    }

    private function assertValidZoneName(mixed $zone, string $label): void
    {
        $this->ensure(
            is_string($zone) && $zone !== '' && strlen($zone) <= 32,
            "{$label} needs a zone name of at most 32 characters."
        );
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['rate_cards' => [$message]]);
        }
    }
}
