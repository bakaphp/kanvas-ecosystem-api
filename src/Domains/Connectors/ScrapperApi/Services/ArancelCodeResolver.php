<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Connectors\ScrapperApi\DataTransferObject\TariffRate;
use Kanvas\Connectors\ScrapperApi\Enums\ArancelSourceEnum;
use Kanvas\Connectors\ScrapperApi\Enums\CustomTaxEnum;
use Kanvas\Inventory\Products\Models\Products;

/**
 * Resolves a product's tariff code without leaving the process: the cached code
 * first, then the keyword map, and a conservative duty if nothing matches. None of
 * the three paths touches the network, so the cart never waits.
 */
final class ArancelCodeResolver
{
    private static ?array $keywordMap = null;

    public function __construct(
        private readonly AppInterface $app
    ) {
    }

    public function resolve(Products $product): TariffRate
    {
        $cached = $product->get(CustomTaxEnum::PRODUCT_ARANCEL_CODE->value);

        if (is_string($cached) && $cached !== '') {
            $tariff = CustomsTariffService::find($cached);

            if ($tariff !== null) {
                return $tariff->withSource(ArancelSourceEnum::CACHED);
            }
        }

        $tariff = $this->matchByKeyword($this->haystack($product));

        if ($tariff !== null) {
            return $tariff->withSource(ArancelSourceEnum::KEYWORD);
        }

        return new TariffRate(
            code: null,
            rate: (int) ($this->app->get(CustomTaxEnum::DEFAULT_RATE->value) ?? CustomTaxEnum::DEFAULT_FALLBACK_RATE),
            itbisExempt: false,
            name: 'Mercancia sin clasificar',
            source: ArancelSourceEnum::FALLBACK,
        );
    }

    public static function remember(Products $product, string $code, ArancelSourceEnum $source): void
    {
        $product->set(CustomTaxEnum::PRODUCT_ARANCEL_CODE->value, $code);
        $product->set(CustomTaxEnum::PRODUCT_ARANCEL_SOURCE->value, $source->value);
    }

    private function matchByKeyword(string $haystack): ?TariffRate
    {
        foreach (self::keywordMap() as $rule) {
            // Lookarounds instead of \b because the patterns carry optional hyphens
            // and spaces ("t-?shirt", "micro ?sd") where \b behaves differently. The
            // trailing (?:e?s)? is what lets singular patterns match the plurals that
            // Amazon titles actually use: "Headphones", "Shoes", "Watches".
            if (preg_match('/(?<![a-z0-9])(' . $rule['pattern'] . ')(?:e?s)?(?![a-z0-9])/i', $haystack) === 1) {
                return CustomsTariffService::find($rule['code']);
            }
        }

        return null;
    }

    private function haystack(Products $product): string
    {
        $parts = [$product->name, $product->description ?? ''];

        foreach ($product->categories as $category) {
            $parts[] = $category->name;
        }

        return Str::lower(Str::ascii(implode(' ', array_filter($parts))));
    }

    private static function keywordMap(): array
    {
        return self::$keywordMap ??= require __DIR__ . '/../Resources/arancel_keyword_map.php';
    }
}
