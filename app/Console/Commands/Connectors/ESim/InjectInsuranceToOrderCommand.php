<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Illuminate\Console\Command;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

class InjectInsuranceToOrderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inject-insurance-to-order {order_id} {--variant-id= : Optional: Manually specify the variant ID to use instead of automatic mapping}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inject insurance structure into an existing order metadata based on eSIM plan duration';

    /**
     * Mapping table: Duration (days) -> Variant ID
     */
    protected const DURATION_VARIANT_MAP = [
        1 => 304791,   // 1 Dia Gratis
        2 => 304792,   // 2 Dias Gratis
        3 => 304793,   // 3 Dias Gratis
        5 => 304794,   // 5 Dias Gratis
        7 => 304799,   // 7 Dias Gratis
        10 => 304795,  // 10 Dias Gratis
        12 => 304800,  // 12 Dias Gratis
        15 => 304796,  // 15 Dias Gratis
        20 => 304797,  // 20 Dias Gratis
        30 => 304798,  // 30 Dias Gratis
        50 => 304810,  // 50 Dias Gratis
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orderId = (int) $this->argument('order_id');

        // Find the order
        $order = Order::find($orderId);

        if (! $order) {
            $this->error("Order with ID {$orderId} not found.");

            return self::FAILURE;
        }

        $this->info("Processing Order ID: {$orderId}");

        // Get metadata
        $metadata = $order->metadata ?? [];

        if (empty($metadata)) {
            $this->error('Order metadata is empty. Nothing to process.');

            return self::FAILURE;
        }

        // Check if esims array exists
        if (! isset($metadata['esims']) || ! is_array($metadata['esims'])) {
            $this->error('esims array not found in order metadata.');

            return self::FAILURE;
        }

        $processedCount = 0;
        $skippedCount = 0;

        // Check if manual variant ID was provided
        $manualVariantId = $this->option('variant-id');
        if ($manualVariantId) {
            $this->info("Using manually specified Variant ID: {$manualVariantId}");
        }

        // Iterate over each eSIM in the esims array
        foreach ($metadata['esims'] as $index => $esimData) {
            $this->info("Processing eSIM #{$index}...");

            // Check if this eSIM already has insurance
            if (isset($esimData['eSimDetails']['insurance']) && ! empty($esimData['eSimDetails']['insurance'])) {
                $this->comment("eSIM #{$index} already has insurance. Skipping.");
                $skippedCount++;

                continue;
            }

            // Determine variant ID and duration
            if ($manualVariantId) {
                // Use manual variant ID
                $variantId = (int) $manualVariantId;

                // Extract duration for the insurance structure
                $duration = $this->extractDuration($esimData);
                if ($duration === null) {
                    $this->warn("Could not extract duration for eSIM #{$index}. Using variant ID {$variantId} anyway.");
                    // Use a default duration or derive from variant if possible
                    $duration = $this->getDurationFromVariantId($variantId) ?? 30;
                }

                $this->info("Using manual Variant ID: {$variantId} with duration: {$duration} days");
            } else {
                // Auto-detect duration and map to variant ID
                $duration = $this->extractDuration($esimData);

                if ($duration === null) {
                    $this->warn("Could not extract duration for eSIM #{$index}. Skipping.");
                    $skippedCount++;

                    continue;
                }

                $this->info("Detected duration: {$duration} days");

                // Map duration to variant ID
                $variantId = $this->mapDurationToVariantId($duration);

                if ($variantId === null) {
                    $this->warn("No variant mapping found for {$duration} days. Skipping eSIM #{$index}.");
                    $skippedCount++;

                    continue;
                }

                $this->info("Mapped to Variant ID: {$variantId}");
            }

            // Fetch variant and product data
            $insuranceStructure = $this->buildInsuranceStructure($variantId, $duration);

            if ($insuranceStructure === null) {
                $this->error("Failed to build insurance structure for Variant ID {$variantId}. Skipping eSIM #{$index}.");
                $skippedCount++;

                continue;
            }

            // Inject insurance into the eSIM eSimDetails
            if (! isset($metadata['esims'][$index]['eSimDetails'])) {
                $metadata['esims'][$index]['eSimDetails'] = [];
            }

            $metadata['esims'][$index]['eSimDetails']['insurance'] = $insuranceStructure;
            $processedCount++;

            $this->info("✓ Insurance structure injected successfully for eSIM #{$index}");
        }

        if ($processedCount === 0) {
            $this->warn("No eSIM entries were processed. ({$skippedCount} skipped)");

            return self::FAILURE;
        }

        // Save updated metadata back to the order
        $order->metadata = $metadata;
        $order->saveOrFail();

        $this->info("✓ Order {$orderId} updated successfully!");
        $this->info("  - Processed: {$processedCount} eSIM(s)");
        $this->info("  - Skipped: {$skippedCount} eSIM(s) (already have insurance or missing data)");

        return self::SUCCESS;
    }

    /**
     * Extract duration from eSIM data
     * Tries multiple possible locations where duration might be stored
     */
    protected function extractDuration(array $esimData): ?int
    {
        // Try from eSimDetails.variantDuration (primary location based on real metadata)
        if (isset($esimData['eSimDetails']['variantDuration']) && is_numeric($esimData['eSimDetails']['variantDuration'])) {
            return (int) $esimData['eSimDetails']['variantDuration'];
        }

        // Try from variant_info.attributes.Variant Duration
        if (isset($esimData['variant_info']['attributes']['Variant Duration']) && is_numeric($esimData['variant_info']['attributes']['Variant Duration'])) {
            return (int) $esimData['variant_info']['attributes']['Variant Duration'];
        }

        // Try from variant_info.attributes.esim_days
        if (isset($esimData['variant_info']['attributes']['esim_days']) && is_numeric($esimData['variant_info']['attributes']['esim_days'])) {
            return (int) $esimData['variant_info']['attributes']['esim_days'];
        }

        // Try from woocommerce_response.order.esim_sale.total_days
        if (isset($esimData['woocommerce_response']['order']['esim_sale']['total_days']) && is_numeric($esimData['woocommerce_response']['order']['esim_sale']['total_days'])) {
            return (int) $esimData['woocommerce_response']['order']['esim_sale']['total_days'];
        }

        // Fallback: Try direct duration field
        if (isset($esimData['duration']) && is_numeric($esimData['duration'])) {
            return (int) $esimData['duration'];
        }

        // Try from eSimDetails.duration
        if (isset($esimData['eSimDetails']['duration']) && is_numeric($esimData['eSimDetails']['duration'])) {
            return (int) $esimData['eSimDetails']['duration'];
        }

        return null;
    }

    /**
     * Map duration to variant ID using the mapping table
     */
    protected function mapDurationToVariantId(int $duration): ?int
    {
        return self::DURATION_VARIANT_MAP[$duration] ?? null;
    }

    /**
     * Get duration from variant ID using reverse mapping
     * Used when variant ID is manually specified
     */
    protected function getDurationFromVariantId(int $variantId): ?int
    {
        $reversedMap = array_flip(self::DURATION_VARIANT_MAP);

        return $reversedMap[$variantId] ?? null;
    }

    /**
     * Build the insurance structure for injection
     */
    protected function buildInsuranceStructure(int $variantId, int $duration): ?array
    {
        // Fetch the variant from the database
        $variant = Variants::with(['product'])->find($variantId);

        if (! $variant) {
            $this->error("Variant with ID {$variantId} not found in database.");

            return null;
        }

        $product = $variant->product;

        if (! $product) {
            $this->error("Product not found for Variant ID {$variantId}.");

            return null;
        }

        // Build the insurance structure
        return [
            'titular' => [
                'plan' => [
                    'id' => (string) $variantId,
                    'name' => 'DOM TELEASISTENCIA SIMLIMITES',
                    'price' => 0,
                    'duration' => (string) $duration,
                ],
                'product' => $this->serializeProduct($product),
                'variant' => $this->serializeVariant($variant),
                'productName' => 'Flight Plus',
            ],
            'dependents' => [],
        ];
    }

    /**
     * Serialize product data for the insurance structure
     */
    protected function serializeProduct(Products $product): array
    {
        return [
            'id' => $product->getId(),
            'uuid' => $product->uuid,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'html_description' => $product->html_description,
            'is_published' => (bool) $product->is_published,
            'apps_id' => $product->apps_id,
            'companies_id' => $product->companies_id,
            'products_types_id' => $product->products_types_id,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Serialize variant data for the insurance structure
     */
    protected function serializeVariant(Variants $variant): array
    {
        return [
            'id' => $variant->getId(),
            'uuid' => $variant->uuid,
            'name' => $variant->name,
            'slug' => $variant->slug,
            'sku' => $variant->sku,
            'description' => $variant->description,
            'short_description' => $variant->short_description,
            'html_description' => $variant->html_description,
            'is_published' => (bool) $variant->is_published,
            'products_id' => $variant->products_id,
            'status_id' => $variant->status_id,
            'ean' => $variant->ean,
            'barcode' => $variant->barcode,
            'serial_number' => $variant->serial_number,
            'weight' => $variant->weight,
            'apps_id' => $variant->apps_id,
            'companies_id' => $variant->companies_id,
            'created_at' => $variant->created_at?->toIso8601String(),
            'updated_at' => $variant->updated_at?->toIso8601String(),
        ];
    }
}
