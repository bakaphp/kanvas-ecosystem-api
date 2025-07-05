<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Actions\CalculateCustomTaxAction;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Variants\Models\Variants;

class SetupCustomTaxCommand extends Command
{
    protected $signature = 'scrapper-api:test-custom-tax {app_id} {variant_id} {--quantity=1}';
    protected $description = 'Test custom tax calculation for a specific product variant';

    public function handle(): void
    {
        $appId = (int) $this->argument('app_id');
        $variantId = (int) $this->argument('variant_id');
        $quantity = (float) $this->option('quantity');

        try {
            $app = Apps::getById($appId);
            $variant = Variants::findOrFail($variantId);

            $this->info('🧪 Testing Custom Tax Calculation');
            $this->newLine();

            // Check prerequisites
            $isEnabled = $app->get(ShippingCostEnum::CUSTOM_TAX_ENABLED->value);
            $hasPrompt = ! empty($app->get(ShippingCostEnum::CUSTOM_TAX_PROMPT->value));

            $this->table(
                ['Setting', 'Status'],
                [
                    ['App', $app->name],
                    ['Custom Tax Enabled', $isEnabled ? '✅ Yes' : '❌ No'],
                    ['Prompt Configured', $hasPrompt ? '✅ Yes' : '❌ No'],
                ]
            );

            if (! $hasPrompt) {
                $this->error('❌ No prompt configured for this app. Cannot test custom tax calculation.');

                return;
            }

            $this->newLine();
            $this->info('📦 Product Information:');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Product Name', $variant->product->name],
                    ['Variant Name', $variant->name],
                    ['SKU', $variant->sku],
                    ['Price', '$' . number_format((float) $variant->getPriceInfoFromDefaultChannel()->price, 2) . ' USD'],
                    ['Weight', ($variant->getAttributeByName('weight_unit')?->value ?? 1) . 'g'],
                    ['Quantity', $quantity],
                ]
            );

            $this->newLine();
            $this->info('🔄 Calculating custom tax...');

            // Test the custom tax calculation
            $customTaxAction = new CalculateCustomTaxAction($app, $variant, $quantity);
            $result = $customTaxAction->execute();

            $this->newLine();
            $this->info('📊 Custom Tax Calculation Result:');

            if ($result['customTax'] > 0) {
                $this->table(
                    ['Tax Component', 'USD', 'RD$'],
                    [
                        ['Arancel', '$' . number_format($result['taxBreakdown']['arancel'] ?? 0, 2), 'RD$' . number_format(($result['taxBreakdown']['arancel'] ?? 0) * 60.25, 2)],
                        ['ITBIS (18%)', '$' . number_format($result['taxBreakdown']['itbis'] ?? 0, 2), 'RD$' . number_format(($result['taxBreakdown']['itbis'] ?? 0) * 60.25, 2)],
                        ['Tasa Aduanal (3%)', '$' . number_format($result['taxBreakdown']['tasaAduanal'] ?? 0, 2), 'RD$' . number_format(($result['taxBreakdown']['tasaAduanal'] ?? 0) * 60.25, 2)],
                        ['ISC/CO₂', '$' . number_format($result['taxBreakdown']['isc'] ?? 0, 2), 'RD$' . number_format(($result['taxBreakdown']['isc'] ?? 0) * 60.25, 2)],
                        ['', '', ''], // Separator
                        ['TOTAL TAX', '$' . number_format($result['customTax'], 2), 'RD$' . number_format($result['customTaxRD'] ?? ($result['customTax'] * 60.25), 2)],
                    ]
                );

                $this->newLine();
                $this->info('📋 Additional Information:');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Product Name (AI)', $result['productName'] ?? 'N/A'],
                        ['Arancel Code', $result['arancelCode'] ?? 'N/A'],
                        ['Country of Origin', $result['countryOrigin'] ?? 'N/A'],
                    ]
                );

                $this->newLine();
                $this->info('🤖 Full AI Response:');
                $this->line('─────────────────────────────────────────────────────────');
                $this->line($result['calculation']);
                $this->line('─────────────────────────────────────────────────────────');

                $this->newLine();
                $this->info('✅ Custom tax calculation completed successfully!');
            } else {
                $this->warn('⚠️  No custom tax calculated.');
                $this->info('Reason: ' . $result['calculation']);

                if (! $isEnabled) {
                    $this->newLine();
                    $this->warn('💡 Note: Custom tax calculation is currently disabled for this app.');
                }
            }
        } catch (Exception $e) {
            $this->error('❌ Error testing custom tax calculation:');
            $this->error($e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}
