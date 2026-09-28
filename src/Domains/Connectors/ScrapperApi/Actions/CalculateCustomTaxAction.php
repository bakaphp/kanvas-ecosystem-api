<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\ScrapperApi\DataTransferObject\TariffRate;
use Kanvas\Connectors\ScrapperApi\Enums\CustomTaxEnum;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Connectors\ScrapperApi\Jobs\RefineArancelCodeJob;
use Kanvas\Connectors\ScrapperApi\Services\ArancelCodeResolver;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Assesses import taxes per Appendix I of the Dominican Republic customs tariff
 * (7th HS Amendment, 2022):
 *
 *     CIF   = FOB + freight + insurance
 *     A     = CIF x duty rate
 *     S     = (CIF + A) x excise rate
 *     ITBIS = (CIF + A + S) x 18%
 *     TI    = A + S + ITBIS
 *
 * Pure arithmetic: no network, no language model. The only fuzzy part — which tariff
 * code the product falls under — is resolved by ArancelCodeResolver from a cached
 * code or a keyword map, and refined in the background for next time.
 */
class CalculateCustomTaxAction
{
    public function __construct(
        protected Variants $variant,
        protected float $quantity,
        protected float $freight = 0.0,
        protected float $insurance = 0.0,
    ) {
    }

    public function execute(): array
    {
        $app = $this->variant->app;

        if (! $app->get(ShippingCostEnum::CUSTOM_TAX_ENABLED->value)) {
            return $this->emptyResult('Custom tax calculation disabled');
        }

        $product = $this->variant->product;
        $tariff = new ArancelCodeResolver($app)->resolve($product);

        $this->queueRefinementIfNeeded($app, $tariff);

        $exchangeRate = (float) ($app->get(CustomTaxEnum::EXCHANGE_RATE->value) ?? CustomTaxEnum::DEFAULT_EXCHANGE_RATE);

        $fob = (float) $this->variant->getPriceInfoFromDefaultChannel()->price * $this->quantity;
        $cif = $this->includesFreight($app)
            ? $fob + $this->freight + $this->insurance
            : $fob;

        $arancelRate = (float) $tariff->rate;
        $arancel = $cif * $arancelRate / 100;

        $iscRate = $this->iscRateFor($app, $tariff);
        $isc = ($cif + $arancel) * $iscRate / 100;

        $itbisRate = $tariff->itbisExempt
            ? 0.0
            : (float) ($app->get(CustomTaxEnum::ITBIS_RATE->value) ?? CustomTaxEnum::DEFAULT_ITBIS_RATE);
        $itbis = ($cif + $arancel + $isc) * $itbisRate / 100;

        $total = $arancel + $isc + $itbis;

        $taxes = [
            'arancel' => $this->component($arancel, $exchangeRate, $arancelRate, 'Arancel'),
            'itbis' => $this->component($itbis, $exchangeRate, $itbisRate, 'ITBIS'),
            // Retained as a zeroed legacy field for existing cart consumers. Appendix I
            // defines total import tax as A + S + ITBIS and contains no additional 3% tax.
            'tasaAduanal' => $this->component(0.0, $exchangeRate, 0.0, 'Tasa Aduanal'),
            'isc' => $this->component($isc, $exchangeRate, $iscRate, 'ISC/CO2'),
        ];

        return [
            'customTax' => round($total, 2),
            'customTaxRD' => round($total * $exchangeRate, 2),
            'arancelCode' => $tariff->code,
            'arancelDescription' => $tariff->name,
            'arancelSource' => $tariff->source->value,
            'countryOrigin' => 'US',
            'productName' => $product->name,
            'cif' => round($cif, 2),
            'cifRD' => round($cif * $exchangeRate, 2),
            'exchangeRate' => $exchangeRate,
            'taxBreakdown' => $taxes,
            'arancel' => $taxes['arancel']['amount_usd'],
            'arancelRD' => $taxes['arancel']['amount_rd'],
            'arancelRate' => $taxes['arancel']['rate'],
            'itbis' => $taxes['itbis']['amount_usd'],
            'itbisRD' => $taxes['itbis']['amount_rd'],
            'itbisRate' => $taxes['itbis']['rate'],
            'tasaAduanal' => $taxes['tasaAduanal']['amount_usd'],
            'tasaAduanalRD' => $taxes['tasaAduanal']['amount_rd'],
            'tasaAduanalRate' => $taxes['tasaAduanal']['rate'],
            'isc' => $taxes['isc']['amount_usd'],
            'iscRD' => $taxes['isc']['amount_rd'],
            'iscDescription' => $taxes['isc']['description'],
            'calculation' => $this->summary($product->name, $tariff, $cif, $taxes, $total, $exchangeRate),
        ];
    }

    /**
     * The excise tax is not in the tariff columns: it comes from the Ley 253-12 list.
     * Configured per app as a code-prefix to rate map, longest matching prefix wins
     * ({"2208": 10} covers the whole spirits heading).
     */
    protected function iscRateFor(AppInterface $app, TariffRate $tariff): float
    {
        $rates = $app->get(CustomTaxEnum::ISC_RATES->value);

        if ($tariff->code === null || ! is_array($rates) || $rates === []) {
            return 0.0;
        }

        $digits = str_replace('.', '', $tariff->code);
        $best = 0.0;
        $bestLength = -1;

        foreach ($rates as $prefix => $rate) {
            $prefix = str_replace('.', '', (string) $prefix);

            if (str_starts_with($digits, $prefix) && strlen($prefix) > $bestLength) {
                $best = (float) $rate;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    protected function queueRefinementIfNeeded(AppInterface $app, TariffRate $tariff): void
    {
        if (! $tariff->source->isRefinable() || ! $app->get(CustomTaxEnum::AI_REFINE_ENABLED->value)) {
            return;
        }

        $product = $this->variant->product;

        // The cart recalculates on every request; without this lock an unclassified
        // product would queue one job per cart view.
        if (! Cache::add('arancel-refine-' . $product->getId(), true, now()->addHours(6))) {
            return;
        }

        RefineArancelCodeJob::dispatch($app, $product);
    }

    protected function includesFreight(AppInterface $app): bool
    {
        $configured = $app->get(CustomTaxEnum::INCLUDE_FREIGHT_IN_CIF->value);

        return $configured === null
            ? true
            : filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    protected function component(float $amount, float $exchangeRate, float $rate, string $description): array
    {
        return [
            'amount_usd' => round($amount, 2),
            'amount_rd' => round($amount * $exchangeRate, 2),
            'rate' => $rate,
            'description' => $description,
        ];
    }

    protected function summary(
        string $productName,
        TariffRate $tariff,
        float $cif,
        array $taxes,
        float $total,
        float $exchangeRate
    ): string {
        $lines = [
            '🛒 Producto: ' . $productName,
            '📦 Código Arancelario: ' . ($tariff->code ?? 'sin clasificar') . ' — ' . $tariff->name,
            '📍 País de Origen: US',
            sprintf('💵 Valor CIF: $%s / RD$%s (tasa %s)', number_format($cif, 2), number_format($cif * $exchangeRate, 2), $exchangeRate),
        ];

        foreach ($taxes as $tax) {
            $lines[] = sprintf(
                '%s (%s%%): $%s / RD$%s',
                $tax['description'],
                $tax['rate'],
                number_format($tax['amount_usd'], 2),
                number_format($tax['amount_rd'], 2)
            );
        }

        $lines[] = sprintf('Total Impuestos: $%s / RD$%s', number_format($total, 2), number_format($total * $exchangeRate, 2));

        return implode("\n", $lines);
    }

    protected function emptyResult(string $reason): array
    {
        $zero = ['amount_usd' => 0.0, 'amount_rd' => 0.0, 'rate' => 0.0];

        return [
            'customTax' => 0.0,
            'customTaxRD' => 0.0,
            'arancelCode' => null,
            'arancelDescription' => null,
            'arancelSource' => null,
            'countryOrigin' => 'US',
            'productName' => $this->variant->product->name,
            'cif' => 0.0,
            'cifRD' => 0.0,
            'exchangeRate' => 0.0,
            'taxBreakdown' => [
                'arancel' => $zero + ['description' => 'Arancel'],
                'itbis' => $zero + ['description' => 'ITBIS'],
                'tasaAduanal' => $zero + ['description' => 'Tasa Aduanal'],
                'isc' => $zero + ['description' => 'ISC/CO2'],
            ],
            'arancel' => 0.0,
            'arancelRD' => 0.0,
            'arancelRate' => 0.0,
            'itbis' => 0.0,
            'itbisRD' => 0.0,
            'itbisRate' => 0.0,
            'tasaAduanal' => 0.0,
            'tasaAduanalRD' => 0.0,
            'tasaAduanalRate' => 0.0,
            'isc' => 0.0,
            'iscRD' => 0.0,
            'iscDescription' => 'ISC/CO2',
            'calculation' => $reason,
        ];
    }
}
