<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Support\Number;
use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Actions\CalculateCustomTaxAction;
use Kanvas\Connectors\ScrapperApi\Enums\CustomTaxEnum;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Variants\Models\Variants;

class SetupCustomTaxCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scrapper-api:test-custom-tax {app_id} {variant_id} {--quantity=1} {--freight=0} {--insurance=0}';
    protected $description = 'Test custom tax calculation for a specific product variant';

    public function handle(): void
    {
        $appId = (int) $this->argument('app_id');
        $variantId = (int) $this->argument('variant_id');
        $quantity = (float) $this->option('quantity');

        try {
            $app = Apps::getById($appId);
            $this->overwriteAppService($app);

            $variant = Variants::findOrFail($variantId);

            $this->info('🧪 Testing Custom Tax Calculation');
            $this->newLine();

            $isEnabled = (bool) $app->get(ShippingCostEnum::CUSTOM_TAX_ENABLED->value);

            $this->table(
                ['Setting', 'Status'],
                [
                    ['App', $app->name],
                    ['Custom Tax Enabled', $isEnabled ? '✅ Yes' : '❌ No'],
                    ['Exchange Rate', $app->get(CustomTaxEnum::EXCHANGE_RATE->value) ?? CustomTaxEnum::DEFAULT_EXCHANGE_RATE],
                    ['AI Refinement', $app->get(CustomTaxEnum::AI_REFINE_ENABLED->value) ? '✅ Yes' : '❌ No'],
                ]
            );

            $this->newLine();
            $this->info('📦 Product Information:');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Product Name', $variant->product->name],
                    ['Variant Name', $variant->name],
                    ['SKU', $variant->sku],
                    ['Price', Number::currency((float) $variant->getPriceInfoFromDefaultChannel()->price, 'USD') . ' USD'],
                    ['Quantity', $quantity],
                ]
            );

            $this->newLine();
            $this->info('🔄 Calculating custom tax...');

            $start = microtime(true);
            $result = new CalculateCustomTaxAction(
                $variant,
                $quantity,
                (float) $this->option('freight'),
                (float) $this->option('insurance'),
            )->execute();
            $elapsed = (microtime(true) - $start) * 1000;

            $this->newLine();

            if ($result['customTax'] <= 0) {
                $this->warn('⚠️  No custom tax calculated.');
                $this->info('Reason: ' . $result['calculation']);

                return;
            }

            $this->info('📊 Custom Tax Calculation Result:');

            $rows = [];

            foreach ($result['taxBreakdown'] as $tax) {
                $rows[] = [
                    $tax['description'] . ' (' . $tax['rate'] . '%)',
                    Number::currency($tax['amount_usd'], 'USD'),
                    Number::currency($tax['amount_rd'], 'DOP', 'es_DO'),
                ];
            }

            $rows[] = ['', '', ''];
            $rows[] = [
                'TOTAL TAX',
                Number::currency($result['customTax'], 'USD'),
                Number::currency($result['customTaxRD'], 'DOP', 'es_DO'),
            ];

            $this->table(['Tax Component', 'USD', 'RD$'], $rows);

            $this->newLine();
            $this->info('📋 Classification:');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Arancel Code', $result['arancelCode'] ?? 'sin clasificar'],
                    ['Description', $result['arancelDescription'] ?? 'N/A'],
                    ['Resolved From', $result['arancelSource'] ?? 'N/A'],
                    ['CIF Value', Number::currency($result['cif'], 'USD') . ' / ' . Number::currency($result['cifRD'], 'DOP', 'es_DO')],
                    ['Elapsed', number_format($elapsed, 2) . ' ms'],
                ]
            );

            $this->newLine();
            $this->line('─────────────────────────────────────────────────────────');
            $this->line($result['calculation']);
            $this->line('─────────────────────────────────────────────────────────');

            $this->newLine();
            $this->info('✅ Custom tax calculation completed successfully!');
        } catch (Exception $e) {
            $this->error('❌ Error testing custom tax calculation:');
            $this->error($e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}
