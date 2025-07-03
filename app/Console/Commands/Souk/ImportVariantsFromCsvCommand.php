<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use League\Csv\Reader;

class ImportVariantsFromCsvCommand extends Command
{
    use KanvasJobsTrait;

    private Apps $app;

    protected $signature = 'kanvas:import-variants-from-csv 
                            {csv_file : Path to the CSV file}
                            {app_id : Application ID}
                            {company_id : Company ID}
                            {--warehouse_id= : Warehouse ID (optional, will use default if not provided)}
                            {--channel_id= : Channel ID (optional, will use default if not provided)}
                            {--provider= : Provider name (e.g., CMLink, VentaMobile)}
                            {--dry-run : Run without making changes}';

    protected $description = 'Import product variants from CSV file by copying from parent variants using cmlink-father-sku';

    public function handle()
    {
        $csvFile = $this->argument('csv_file');
        $appId = (int) $this->argument('app_id');
        $companyId = (int) $this->argument('company_id');
        $warehouseId = $this->option('warehouse_id') ? (int) $this->option('warehouse_id') : null;
        $channelId = $this->option('channel_id') ? (int) $this->option('channel_id') : null;
        $provider = $this->option('provider') ?? 'Unknown';
        $dryRun = $this->option('dry-run');

        if (! file_exists($csvFile)) {
            $this->error("CSV file not found: {$csvFile}");
            return 1;
        }

        $this->app = Apps::getById($appId);
        $company = Companies::getById($companyId);

        if ($warehouseId) {
            $warehouse = Warehouses::getById($warehouseId);
        } else {
            $warehouse = Warehouses::getDefault($company);
            if (! $warehouse) {
                $this->error('No default warehouse found for company. Please specify --warehouse_id or create a default warehouse.');
                return 1;
            }
        }

        if ($channelId) {
            $channel = Channels::getById($channelId);
        } else {
            $channel = Channels::getDefault($company);
            if (! $channel) {
                $this->error('No default channel found for company. Please specify --channel_id or create a default channel.');
                return 1;
            }
        }

        $this->info("Starting import from: {$csvFile}");
        $this->info("Provider: {$provider}");
        $this->info("App: {$this->app->name} (ID: {$appId})");
        $this->info("Company: {$company->name} (ID: {$companyId})");
        $this->info("Warehouse: {$warehouse->name} (ID: {$warehouse->getId()})");
        $this->info("Channel: {$channel->name} (ID: {$channel->getId()})");

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
        }

        $csv = Reader::createFromPath($csvFile, 'r');
        $csv->setHeaderOffset(0);

        $records = $csv->getRecords();
        $processed = 0;
        $created = 0;
        $updated = 0;
        $errors = 0;

        DB::beginTransaction();

        try {
            foreach ($records as $record) {
                $this->line("Processing record: " . json_encode($record));
                
                if ($this->isEmptyRecord($record)) {
                    continue;
                }

                $result = $this->processRecord($record, $company, $warehouse, $channel, $provider, $dryRun);
                
                $processed++;
                
                if ($result['status'] === 'created') {
                    $created++;
                } elseif ($result['status'] === 'updated') {
                    $updated++;
                } elseif ($result['status'] === 'error') {
                    $errors++;
                    $this->error("Error processing record: " . $result['message']);
                }

                if ($processed % 10 === 0) {
                    $this->info("Processed {$processed} records...");
                }
            }

            if ($dryRun) {
                DB::rollBack();
                $this->info("DRY RUN completed - no changes were made");
            } else {
                DB::commit();
                $this->info("Import completed successfully");
            }

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Processed', $processed],
                    ['Created', $created],
                    ['Updated', $updated],
                    ['Errors', $errors],
                ]
            );

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Import failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Check if a record is empty or should be skipped
     */
    protected function isEmptyRecord(array $record): bool
    {
        // Skip records where essential fields are empty or contain only header-like data
        $internalProduct = trim($record['internal-product'] ?? '');
        $cmlinkFatherSku = trim($record['cmlink-father-sku'] ?? '');
        $price = trim($record['Price Simlimites'] ?? '');
        
        if (empty($internalProduct) ||
            empty($cmlinkFatherSku) ||
            empty($price) ||
            $internalProduct === 'internal-product' ||
            $cmlinkFatherSku === 'cmlink-father-sku') {
            return true;
        }

        return false;
    }

    /**
     * Process a single CSV record
     */
    protected function processRecord(array $record, Companies $company, Warehouses $warehouse, Channels $channel, string $provider, bool $dryRun): array
    {
        try {
            $country = trim($record['pais'] ?? '');
            $network = trim($record['network'] ?? '');
            $days = (int) ($record['Dias'] ?? 0);
            $dataGb = (float) ($record['GB'] ?? 0);
            $dataMb = (int) ($record['MB'] ?? 0);
            $price = (float) ($record['Price Simlimites'] ?? 0);
            $internalProduct = trim($record['internal-product'] ?? '');
            $cmlinkFatherSku = trim($record['cmlink-father-sku'] ?? '');

            // Find existing product by internal-product reference
            $product = $this->findExistingProduct($internalProduct, $company);

            if (! $product) {
                return ['status' => 'error', 'message' => "Product not found for ID: {$internalProduct} or not numeric"];
            }

            $this->line("Found product: {$product->name} (ID: {$product->getId()})");

            // Find parent variant by cmlink-father-sku
            $parentVariant = $this->findParentVariant($cmlinkFatherSku, $company);

            if (! $parentVariant) {
                return ['status' => 'error', 'message' => "Parent variant not found for cmlink-father-sku: {$cmlinkFatherSku}"];
            }

            $this->line("Found parent variant: {$parentVariant->name} (SKU: {$parentVariant->sku})");

            // Copy variant from parent, keeping the same SKU but for the new product
            $variant = $this->copyVariantFromParent($parentVariant, $product, $country, $network, $days, $dataGb, $dataMb, $price, $warehouse, $channel, $dryRun);

            if ($variant) {
                return ['status' => 'created', 'variant' => $variant];
            } else {
                return ['status' => 'error', 'message' => 'Failed to copy variant from parent'];
            }

        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Find existing product by internal product ID
     */
    protected function findExistingProduct(string $internalProduct, Companies $company): ?Products
    {
        if (empty($internalProduct) || !is_numeric($internalProduct)) {
            return null;
        }

        $product = Products::where('id', (int) $internalProduct)
                          ->where('companies_id', $company->getId())
                          ->where('apps_id', $this->app->getId())
                          ->first();

        return $product;
    }

    /**
     * Find parent variant by cmlink-father-sku
     */
    protected function findParentVariant(string $cmlinkFatherSku, Companies $company): ?Variants
    {
        if (empty($cmlinkFatherSku)) {
            return null;
        }

        // Find variant by SKU within the same company and app
        $variant = Variants::where('sku', $cmlinkFatherSku)
                          ->whereHas('product', function ($query) use ($company) {
                              $query->where('companies_id', $company->getId())
                                    ->where('apps_id', $this->app->getId());
                          })
                          ->first();

        return $variant;
    }

    /**
     * Copy variant from parent variant to new product
     */
    protected function copyVariantFromParent(
        Variants $parentVariant, 
        Products $targetProduct, 
        string $country,
        string $network,
        int $days,
        float $dataGb,
        int $dataMb,
        float $price,
        Warehouses $warehouse, 
        Channels $channel, 
        bool $dryRun
    ): ?Variants {
        if ($dryRun) {
            return null;
        }

        // Check if variant already exists in target product (with CMLINK prefix)
        $uniqueSku = 'CMLINK-' . $parentVariant->sku;
        $existingVariant = Variants::where('sku', $uniqueSku)
                                  ->where('products_id', $targetProduct->getId())
                                  ->first();

        if ($existingVariant) {
            $this->line("Variant already exists in target product: {$existingVariant->sku}");
            // Update the existing variant with new price and CSV data
            $this->updateVariantWithCsvData($existingVariant, $price, $days, $dataGb, $dataMb, $country, $network, $channel);
            return $existingVariant;
        }

        // Create new variant copying from parent with unique SKU
        $uniqueSku = 'CMLINK-' . $parentVariant->sku;
        
        $variant = new Variants();
        $variant->products_id = $targetProduct->getId();
        $variant->name = $parentVariant->name;
        $variant->sku = $uniqueSku;
        $variant->price = $price; // Use price from CSV instead of parent
        $variant->companies_id = $targetProduct->companies_id;
        $variant->users_id = $targetProduct->users_id;
        $variant->apps_id = $targetProduct->apps_id;
        $variant->is_published = $parentVariant->is_published;
        $variant->description = $parentVariant->description;
        $variant->saveOrFail();

        // Copy all attributes from parent variant and add CSV data
        $this->copyVariantAttributesWithCsvData($parentVariant, $variant, $days, $dataGb, $dataMb, $country, $network);

        // Create channel pricing for this variant
        $this->createVariantChannelPricing($variant, $channel, $price);

        $this->line("Created variant: {$variant->sku} for product: {$targetProduct->name} with price: {$price}");

        return $variant;
    }

    /**
     * Copy all attributes from parent variant to new variant with CSV data
     */
    protected function copyVariantAttributesWithCsvData(
        Variants $parentVariant, 
        Variants $targetVariant, 
        int $days, 
        float $dataGb, 
        int $dataMb, 
        string $country, 
        string $network
    ): void {
        // Get all attributes from parent variant
        $parentAttributes = $parentVariant->attributeValues()->with('attribute')->get();

        foreach ($parentAttributes as $parentAttribute) {
            $attribute = $parentAttribute->attribute;
            if ($attribute) {
                (new AddAttributeAction($targetVariant, $attribute, $parentAttribute->value))->execute();
            }
        }

        // Map CSV data to existing attributes
        $this->mapCsvDataToAttributes($targetVariant, $parentVariant->sku, $days, $dataGb, $dataMb, $country, $network);
    }

    /**
     * Update existing variant with CSV data using attributes
     */
    protected function updateVariantWithCsvData(
        Variants $variant, 
        float $price, 
        int $days, 
        float $dataGb, 
        int $dataMb, 
        string $country, 
        string $network, 
        Channels $channel
    ): void {
        // Update variant price
        $variant->price = $price;
        $variant->saveOrFail();

        // Update CSV data attributes by mapping to existing attributes
        $this->mapCsvDataToAttributes($variant, '', $days, $dataGb, $dataMb, $country, $network);

        // Update channel pricing
        $this->createVariantChannelPricing($variant, $channel, $price);
    }

    /**
     * Create or update channel pricing for a variant
     */
    protected function createVariantChannelPricing(Variants $variant, Channels $channel, float $price): void
    {
        // This would typically use the VariantChannel relationship
        // Check if channel pricing already exists
        $existingChannelPricing = $variant->variantChannels()
            ->where('channels_id', $channel->getId())
            ->first();

        if ($existingChannelPricing) {
            $existingChannelPricing->price = $price;
            $existingChannelPricing->saveOrFail();
            $this->line("Updated channel pricing: {$price} for channel: {$channel->name}");
        } else {
            // Create new channel pricing - you may need to adjust this based on your VariantChannel model
            $variant->variantChannels()->create([
                'channels_id' => $channel->getId(),
                'price' => $price,
                'is_published' => 1,
            ]);
            $this->line("Created channel pricing: {$price} for channel: {$channel->name}");
        }
    }

    /**
     * Map CSV data to existing variant attributes
     */
    protected function mapCsvDataToAttributes(
        Variants $variant, 
        string $fatherSku, 
        int $days, 
        float $dataGb, 
        int $dataMb, 
        string $country, 
        string $network
    ): void {
        // Map CSV data to existing attribute names based on the variant structure
        $attributeMapping = [
            'esim_days' => $days,
            'Variant Duration' => $days,
            'Data' => $dataGb > 0 ? "{$dataGb}GB" : "{$dataMb}MB",
            'coverages' => $country,
            'Region' => $country,
            'Variant Network' => $network,
            'Variant Product Provider' => 'CMLink',
        ];

        // Add father SKU only if provided (for new variants, not updates)
        if (!empty($fatherSku)) {
            $attributeMapping['CMLink Father SKU'] = $fatherSku;
        }

        // Update each existing attribute with the mapped value
        foreach ($attributeMapping as $attributeName => $value) {
            $this->updateExistingAttribute($variant, $attributeName, $value);
        }
    }

    /**
     * Update an existing attribute value for a variant
     */
    protected function updateExistingAttribute(Variants $variant, string $attributeName, mixed $value): void
    {
        // Find existing attribute by name (case-insensitive and slug variants)
        $attribute = Attributes::where('companies_id', $variant->companies_id)
                              ->where('apps_id', $variant->apps_id)
                              ->where(function ($query) use ($attributeName) {
                                  $query->where('name', $attributeName)
                                        ->orWhere('name', 'LIKE', $attributeName)
                                        ->orWhere('slug', \Illuminate\Support\Str::slug($attributeName));
                              })
                              ->first();

        if ($attribute) {
            // Use AddAttributeAction to update the attribute value
            (new AddAttributeAction($variant, $attribute, $value))->execute();
            $this->line("Updated attribute '{$attributeName}' with value: {$value}");
        } else {
            $this->line("Warning: Attribute '{$attributeName}' not found for variant {$variant->sku}");
        }
    }
}
