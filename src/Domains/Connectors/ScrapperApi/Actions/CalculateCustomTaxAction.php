<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

class CalculateCustomTaxAction
{
    public function __construct(
        protected Apps $app,
        protected Variants $variant,
        protected float $quantity,
        protected array $item = []
    ) {
    }

    public function execute(): array
    {
        // Check if custom tax calculation is enabled
        if (! $this->app->get(ShippingCostEnum::CUSTOM_TAX_ENABLED->value)) {
            return [
                'customTax' => 0,
                'arancelCode' => null,
                'countryOrigin' => 'US',
                'calculation' => 'Custom tax calculation disabled',
            ];
        }

        // Get the prompt from app settings
        $prompt = $this->app->get(ShippingCostEnum::CUSTOM_TAX_PROMPT->value);

        if (! $prompt) {
            return [
                'customTax' => 0,
                'arancelCode' => null,
                'countryOrigin' => 'US',
                'calculation' => 'Custom tax prompt not configured',
            ];
        }

        try {
            // Get product information
            $productName = $this->variant->product->name;
            $productPrice = $this->variant->getPriceInfoFromDefaultChannel()->price;
            $productWeight = $this->variant->getAttributeByName('weight_unit')?->value ?? 1;
            $productUrl = $this->item['product_url'] ?? $this->variant->product->getAttributeBySlug('product_url')?->value ?? 'https://www.amazon.com/' . $this->variant->product->slug . '/dp/' . $this->variant->id . '?th=1';

            // Prepare the input for Prism
            $productInfo = "Product URL: {$productUrl}\n";
            $productInfo .= "Product Name: {$productName}\n";
            $productInfo .= "Product Price: \${$productPrice} USD\n";
            $productInfo .= "Product Weight: {$productWeight}g\n";
            $productInfo .= "Quantity: {$this->quantity}";

            // Use Prism to calculate custom tax
            $response = Prism::text()
                ->using(Provider::Gemini, 'gemini-2.0-flash')
                ->withPrompt($prompt . "\n\nProduct Information:\n" . $productInfo)
                ->asText();

            // Parse the response to extract tax information
            return $this->parseCustomTaxResponse($response->text);
        } catch (Exception $e) {
            // Log the error and return default values
            report($e);

            return [
                'customTax' => 0,
                'arancelCode' => null,
                'countryOrigin' => 'US',
                'calculation' => 'Error calculating custom tax: ' . $e->getMessage(),
            ];
        }
    }

    protected function parseCustomTaxResponse(string $response): array
    {
        // Initialize default values
        $productName = '';
        $arancelCode = null;
        $countryOrigin = 'US';
        $totalTaxesUSD = 0;
        $totalTaxesRD = 0;

        // Extract product name from response
        if (preg_match('/🛒 Producto: (.+)/', $response, $matches)) {
            $productName = trim($matches[1]);
        }

        // Extract arancel code
        if (preg_match('/📦 Código Arancelario: (.+)/', $response, $matches)) {
            $arancelCode = trim($matches[1]);
        }

        // Extract country of origin
        if (preg_match('/📍 País de Origen: (.+)/', $response, $matches)) {
            $countryOrigin = trim($matches[1]);
        }

        // Extract total taxes in USD and RD$ - Updated to handle table format
        // Try original format first
        if (preg_match('/Total Impuestos\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $totalTaxesUSD = (float) str_replace(',', '', $matches[1]);
            $totalTaxesRD = (float) str_replace(',', '', $matches[2]);
        }
        // Try table format with pipes
        elseif (preg_match('/\|\s*Total Impuestos\s*\|\s*\$([0-9,]+\.?[0-9]*)\s*\|\s*RD\$([0-9,]+\.?[0-9]*)\s*\|/', $response, $matches)) {
            $totalTaxesUSD = (float) str_replace(',', '', $matches[1]);
            $totalTaxesRD = (float) str_replace(',', '', $matches[2]);
        }

        // Extract individual tax components with more detailed structure
        $taxes = [
            'arancel' => [
                'amount_usd' => 0,
                'amount_rd' => 0,
                'rate' => 0,
                'description' => 'Arancel',
            ],
            'itbis' => [
                'amount_usd' => 0,
                'amount_rd' => 0,
                'rate' => 18,
                'description' => 'ITBIS',
            ],
            'tasaAduanal' => [
                'amount_usd' => 0,
                'amount_rd' => 0,
                'rate' => 3,
                'description' => 'Tasa Aduanal',
            ],
            'isc' => [
                'amount_usd' => 0,
                'amount_rd' => 0,
                'rate' => 0,
                'description' => 'ISC/CO2',
            ],
        ];

        // Parse tax breakdown - Updated to handle table format
        // Arancel
        if (preg_match('/Arancel \(([0-9]+)%\)\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['arancel']['rate'] = (int) $matches[1];
            $taxes['arancel']['amount_usd'] = (float) str_replace(',', '', $matches[2]);
            $taxes['arancel']['amount_rd'] = (float) str_replace(',', '', $matches[3]);
        } elseif (preg_match('/\|\s*Arancel \(([0-9]+)%\)\s*\|\s*\$([0-9,]+\.?[0-9]*)\s*\|\s*RD\$([0-9,]+\.?[0-9]*)\s*\|/', $response, $matches)) {
            $taxes['arancel']['rate'] = (int) $matches[1];
            $taxes['arancel']['amount_usd'] = (float) str_replace(',', '', $matches[2]);
            $taxes['arancel']['amount_rd'] = (float) str_replace(',', '', $matches[3]);
        }

        // ITBIS
        if (preg_match('/ITBIS \(18%\)\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['itbis']['amount_usd'] = (float) str_replace(',', '', $matches[1]);
            $taxes['itbis']['amount_rd'] = (float) str_replace(',', '', $matches[2]);
        } elseif (preg_match('/\|\s*ITBIS \(18%\)\s*\|\s*\$([0-9,]+\.?[0-9]*)\s*\|\s*RD\$([0-9,]+\.?[0-9]*)\s*\|/', $response, $matches)) {
            $taxes['itbis']['amount_usd'] = (float) str_replace(',', '', $matches[1]);
            $taxes['itbis']['amount_rd'] = (float) str_replace(',', '', $matches[2]);
        }

        // Tasa Aduanal
        if (preg_match('/Tasa Aduanal \(3%\)\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['tasaAduanal']['amount_usd'] = (float) str_replace(',', '', $matches[1]);
            $taxes['tasaAduanal']['amount_rd'] = (float) str_replace(',', '', $matches[2]);
        } elseif (preg_match('/\|\s*Tasa Aduanal \(3%\)\s*\|\s*\$([0-9,]+\.?[0-9]*)\s*\|\s*RD\$([0-9,]+\.?[0-9]*)\s*\|/', $response, $matches)) {
            $taxes['tasaAduanal']['amount_usd'] = (float) str_replace(',', '', $matches[1]);
            $taxes['tasaAduanal']['amount_rd'] = (float) str_replace(',', '', $matches[2]);
        }

        // Check for ISC or CO2 taxes
        if (preg_match('/\[([^\]]+)\]\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['isc']['description'] = trim($matches[1]);
            $taxes['isc']['amount_usd'] = (float) str_replace(',', '', $matches[2]);
            $taxes['isc']['amount_rd'] = (float) str_replace(',', '', $matches[3]);
        } elseif (preg_match('/\|\s*\[([^\]]+)\]\s*\|\s*\$([0-9,]+\.?[0-9]*)\s*\|\s*RD\$([0-9,]+\.?[0-9]*)\s*\|/', $response, $matches)) {
            $taxes['isc']['description'] = trim($matches[1]);
            $taxes['isc']['amount_usd'] = (float) str_replace(',', '', $matches[2]);
            $taxes['isc']['amount_rd'] = (float) str_replace(',', '', $matches[3]);
        }

        // If we couldn't extract total from the pattern, try to calculate it from components
        if ($totalTaxesUSD === 0) {
            $totalTaxesUSD = $taxes['arancel']['amount_usd'] + $taxes['itbis']['amount_usd'] +
                           $taxes['tasaAduanal']['amount_usd'] + $taxes['isc']['amount_usd'];
        }

        if ($totalTaxesRD === 0) {
            $totalTaxesRD = $taxes['arancel']['amount_rd'] + $taxes['itbis']['amount_rd'] +
                          $taxes['tasaAduanal']['amount_rd'] + $taxes['isc']['amount_rd'];
        }

        return [
            'customTax' => $totalTaxesUSD,
            'customTaxRD' => $totalTaxesRD,
            'arancelCode' => $arancelCode,
            'countryOrigin' => $countryOrigin,
            'productName' => $productName,
            'taxBreakdown' => $taxes,
            // Add individual tax details at the root level for easy access
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
            'calculation' => $response,
        ];
    }
}
