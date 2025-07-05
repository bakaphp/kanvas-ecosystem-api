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

        // Extract total taxes in USD and RD$
        if (preg_match('/Total Impuestos\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $totalTaxesUSD = (float) str_replace(',', '', $matches[1]);
            $totalTaxesRD = (float) str_replace(',', '', $matches[2]);
        }

        // Extract individual tax components
        $taxes = [
            'arancel' => 0,
            'itbis' => 0,
            'tasaAduanal' => 0,
            'isc' => 0,
        ];

        // Parse tax breakdown
        if (preg_match('/Arancel \(([0-9]+)%\)\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['arancel'] = (float) str_replace(',', '', $matches[2]);
        }

        if (preg_match('/ITBIS \(18%\)\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['itbis'] = (float) str_replace(',', '', $matches[1]);
        }

        if (preg_match('/Tasa Aduanal \(3%\)\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['tasaAduanal'] = (float) str_replace(',', '', $matches[1]);
        }

        // Check for ISC or CO2 taxes
        if (preg_match('/\[([^\]]+)\]\s+\$([0-9,]+\.?[0-9]*)\s+RD\$([0-9,]+\.?[0-9]*)/', $response, $matches)) {
            $taxes['isc'] = (float) str_replace(',', '', $matches[2]);
        }

        // If we couldn't extract total from the pattern, try to calculate it from components
        if ($totalTaxesUSD === 0) {
            $totalTaxesUSD = array_sum($taxes);
        }

        return [
            'customTax' => $totalTaxesUSD,
            'customTaxRD' => $totalTaxesRD,
            'arancelCode' => $arancelCode,
            'countryOrigin' => $countryOrigin,
            'productName' => $productName,
            'taxBreakdown' => $taxes,
            'calculation' => $response,
        ];
    }
}
