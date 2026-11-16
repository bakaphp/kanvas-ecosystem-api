<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Baka\Support\Str;
use Kanvas\Workflow\Rules\Support\SafeExpression;
use Throwable;

/**
 * Reproduces BrushCrazy's `CalculatesAddons` trait. BrushCrazy never stores what an add-on cost:
 * a sale item keeps only the add-on *names*, and every total — receipts, reports, refunds — is
 * recalculated live from the current `pos_settings`. So this is the only way to arrive at the
 * figure BrushCrazy itself shows, and it inherits the same limitation: a setting repriced since
 * the sale reprices the history too. BrushCrazy reads archived settings as well
 * (`withTrashed()`, live rows first), and so does this class.
 *
 * Two source behaviours are kept on purpose because they decide real totals:
 * - add-ons are matched as map keys (`Arr::has`). A refunded or mirrored item stores them as a
 *   list, so it is priced without add-ons — which is exactly what BrushCrazy refunded.
 * - a formula that fails to evaluate is free, not an error.
 *
 * Formulas went through mossadal/math-parser at the source; here they run through SafeExpression
 * after translating its comma decimals (`1,5`), `^` (power) and implicit multiplication (`2a`). Its named functions
 * (sqrt, round, ...) are not supported and price at zero.
 */
final class AddonPricer
{
    /**
     * Built-in add-ons in the order `SaleItem::subtotal()` adds them: [add-on name, setting name,
     * how the setting is read]. Several price off a differently-named setting.
     */
    private const array BUILT_IN = [
        ['Grout Fee', 'Grout Fee', 'fixed'],
        ['Sketch Fee', 'Sketch Fee', 'fixed'],
        ['Stencil Fee', 'Stencil Fee', 'fixed'],
        ['Wax Resist', 'Wax Resist', 'fixed'],
        ['Fluid Art', 'Fluid Art', 'setting'],
        ['Bubble Art', 'Bubble Art Formula', 'formula'],
        ['Hydro Dipping', 'Hydro Dipping Formula', 'formula'],
        ['Marble Art', 'Marble Art Formula', 'formula'],
        ['Mosaic', 'Mosaic Formula', 'formula'],
        ['Mother of Pearl', 'Mother of Pearl', 'formula'],
        ['Pouring Art', 'Pouring Art Formula', 'formula'],
        ['Gold Accent', 'Gold Accent', 'fixed'],
        ['Silver Accent', 'Silver Accent', 'fixed'],
    ];

    /** Counted add-ons: [item column, setting name]. */
    private const array PER_UNIT = [
        ['extra_colors', 'Extra Color'],
        ['extra_gold', 'Extra Gold'],
        ['extra_silver', 'Extra Silver'],
    ];

    /**
     * @param  array<string, object>  $settings  name => setting row
     */
    private function __construct(
        private readonly array $settings,
    ) {
    }

    /**
     * @param  iterable<object>  $rows  `pos_settings` with live rows before archived ones
     */
    public static function fromRows(iterable $rows): self
    {
        $settings = [];

        foreach ($rows as $row) {
            // First one wins, as `firstWhere('name', ...)` does at the source.
            $settings[(string) $row->name] ??= $row;
        }

        return new self($settings);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Per-unit cost of each add-on on the item, in the order the source adds them. An add-on whose
     * setting no longer exists is listed at zero rather than dropped, so the usage stays visible.
     *
     * @return array<int, array{name: string, cents: int}>
     */
    public function breakdown(object $item): array
    {
        $addons = Str::jsonToArray($item->addons ?? null);
        $addons = is_array($addons) ? $addons : [];
        $priceCents = Money::fromRaw($item->price ?? null)->cents;
        $breakdown = [];

        foreach (self::BUILT_IN as [$name, $settingName, $kind]) {
            if (! array_key_exists($name, $addons)) {
                continue;
            }

            $setting = $this->settings[$settingName] ?? null;

            $breakdown[] = [
                'name' => $name,
                'cents' => match ($kind) {
                    'fixed' => self::fixedCents($setting),
                    'formula' => self::formulaCents($setting, $priceCents),
                    default => self::settingCents($setting, $priceCents),
                },
            ];
        }

        foreach (self::PER_UNIT as [$column, $settingName]) {
            $count = (int) ($item->{$column} ?? 0);

            if ($count > 0) {
                $breakdown[] = [
                    'name' => $settingName . ' x' . $count,
                    'cents' => self::fixedCents($this->settings[$settingName] ?? null) * $count,
                ];
            }
        }

        if ((bool) ($item->double_sided ?? false)) {
            $breakdown[] = [
                'name' => 'Double-sided',
                'cents' => self::formulaCents($this->settings['Double-sided Formula'] ?? null, $priceCents),
            ];
        }

        $builtInNames = array_column(self::BUILT_IN, 0);

        foreach (array_keys($addons) as $name) {
            // A list-shaped column yields integer keys here, which name no setting.
            if (! is_string($name) || in_array($name, $builtInNames, true) || ! isset($this->settings[$name])) {
                continue;
            }

            $breakdown[] = ['name' => $name, 'cents' => self::settingCents($this->settings[$name], $priceCents)];
        }

        return $breakdown;
    }

    public function unitCents(object $item): int
    {
        return array_sum(array_column($this->breakdown($item), 'cents'));
    }

    /**
     * `getAmount()`: the setting's amount as money. A percent-shaped amount is not money and the
     * source's formatter refuses it, which it reads as zero.
     */
    private static function fixedCents(?object $setting): int
    {
        if ($setting === null || str_contains((string) ($setting->amount ?? ''), 'percent')) {
            return 0;
        }

        return Money::fromRaw($setting->amount ?? null)->cents;
    }

    /** `amountForSetting()`: what lets a studio-created add-on be a formula, a percent or a price. */
    private static function settingCents(?object $setting, int $priceCents): int
    {
        if ($setting === null) {
            return 0;
        }

        return match ((string) $setting->type) {
            'formula' => self::formulaCents($setting, $priceCents),
            'percent' => (int) round($priceCents * self::basisPoints($setting) / 10000),
            default => self::fixedCents($setting),
        };
    }

    /** `calculateAmount()`: evaluated against the unit price in dollars as `a`. */
    private static function formulaCents(?object $setting, int $priceCents): int
    {
        if ($setting === null) {
            return 0;
        }

        try {
            $value = SafeExpression::evaluateText(
                self::toExpressionLanguage(self::formula($setting)),
                ['a' => $priceCents / 100]
            );
        } catch (Throwable) {
            return 0;
        }

        if ((! is_int($value) && ! is_float($value)) || ! is_finite($value * 100)) {
            return 0;
        }

        return (int) round($value * 100);
    }

    /**
     * `PosSetting::formula()`: a formula row stores "tag|expression", or a bare expression when it
     * predates tags. A non-formula row has no formula and the source falls back to its raw value.
     */
    private static function formula(object $setting): string
    {
        $value = (string) ($setting->value ?? '');

        if ((string) $setting->type !== 'formula') {
            return $value;
        }

        $pipe = strpos($value, '|');

        return trim($pipe === false ? $value : substr($value, $pipe + 1));
    }

    private static function toExpressionLanguage(string $formula): string
    {
        // `.25` → `0.25`, as the source's normalizeFormula() does for its lexer.
        $formula = (string) preg_replace('/(?<![\d.,])[.,](\d)/', '0.$1', $formula);
        // math-parser's number token takes a comma as the decimal point too (`1,5`).
        $formula = (string) preg_replace('/(\d),(\d)/', '$1.$2', $formula);
        $formula = str_replace('^', '**', $formula);

        return (string) preg_replace('/(\d|\))\s*(?=[a(])/', '$1*', $formula);
    }

    private static function basisPoints(object $setting): int
    {
        $amount = Str::jsonToArray($setting->amount ?? null);

        return is_array($amount) ? (int) ($amount['percent'] ?? 0) : 0;
    }
}
