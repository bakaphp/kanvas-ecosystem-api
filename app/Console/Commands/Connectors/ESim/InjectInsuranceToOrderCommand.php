<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Illuminate\Console\Command;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

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
            $result = $this->buildInsuranceStructure($variantId, $duration);

            if ($result === null) {
                $this->error("Failed to build insurance structure for Variant ID {$variantId}. Skipping eSIM #{$index}.");
                $skippedCount++;

                continue;
            }

            $insuranceStructure = $result['structure'];
            $variant = $result['variant'];

            // 1. Inject insurance into order metadata (independent check)
            $alreadyInOrderMetadata = isset($metadata['esims'][$index]['eSimDetails']['insurance']) && ! empty($metadata['esims'][$index]['eSimDetails']['insurance']);

            if (! $alreadyInOrderMetadata) {
                if (! isset($metadata['esims'][$index]['eSimDetails'])) {
                    $metadata['esims'][$index]['eSimDetails'] = [];
                }

                $metadata['esims'][$index]['eSimDetails']['insurance'] = $insuranceStructure;
                $this->info("  ✓ Insurance injected into order metadata for eSIM #{$index}");
            } else {
                $this->comment("  Insurance already exists in order metadata for eSIM #{$index}. Skipping order metadata injection.");
            }

            // 2. Create order item for Flight Plus insurance (independent check)
            $this->createOrderItem($order, $variant, $duration);

            // 3. Inject insurance into the message (woocommerce_response) (independent check)
            $this->injectInsuranceToMessage($order, $insuranceStructure, $index);

            $processedCount++;

            $this->info("✓ Insurance processing completed for eSIM #{$index}");
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
     * Returns array with 'structure' and 'variant' keys
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
        $structure = [
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

        return [
            'structure' => $structure,
            'variant' => $variant,
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

    /**
     * Create order item for Flight Plus insurance
     */
    protected function createOrderItem(Order $order, Variants $variant, int $duration): void
    {
        $product = $variant->product;

        // Check if order item already exists for this variant
        $existingItem = OrderItem::where('order_id', $order->getId())
            ->where('variant_id', $variant->getId())
            ->first();

        if ($existingItem) {
            $this->comment("  Order item for variant {$variant->getId()} already exists. Skipping creation.");
            return;
        }

        // Create the order item
        $orderItem = new OrderItem();
        $orderItem->apps_id = $order->apps_id;
        $orderItem->product_name = $product->name; // Flight Plus
        $orderItem->product_sku = $variant->sku; // e.g., 1-EO7ZXOA-10DaysFree
        $orderItem->quantity = 1.00;
        $orderItem->unit_price_net_amount = 0.00;
        $orderItem->unit_price_gross_amount = 0.00;
        $orderItem->is_shipping_required = true;
        $orderItem->order_id = $order->getId();
        $orderItem->quantity_fulfilled = 0.00;
        $orderItem->variant_id = $variant->getId();
        $orderItem->tax_rate = 0.00;
        $orderItem->currency = 'USD';
        $orderItem->variant_name = $variant->name; // e.g., "10 Dias Gratis"
        $orderItem->is_public = true;

        $orderItem->saveOrFail();

        $this->info("  ✓ Order item created for Flight Plus ({$variant->name})");
    }

    /**
     * Inject insurance structure into the message at two locations
     */
    protected function injectInsuranceToMessage(Order $order, array $insuranceStructure, int $esimIndex): void
    {
        // Get the message_id from the esim metadata
        $metadata = $order->metadata ?? [];

        if (! isset($metadata['esims'][$esimIndex]['message_id'])) {
            $this->warn("  No message_id found for eSIM #{$esimIndex}. Skipping message injection.");
            return;
        }

        $messageId = $metadata['esims'][$esimIndex]['message_id'];

        try {
            $message = Message::getById($messageId);
        } catch (\Exception $e) {
            $this->warn("  Message {$messageId} not found. Skipping message injection.");
            return;
        }

        $messageData = $message->message;

        // Location 1: woocommerce_response.variant_info.eSimDetails.insurance
        // Assume woocommerce_response.variant_info.eSimDetails already exists
        if (isset($messageData['woocommerce_response']['variant_info']['eSimDetails']['insurance'])) {
            $this->comment("  Insurance already exists in variant_info for message {$messageId}. Skipping variant_info injection.");
        } else {
            $messageData['woocommerce_response']['variant_info']['eSimDetails']['insurance'] = $insuranceStructure;
            $this->info("  ✓ Insurance injected into variant_info.eSimDetails for message {$messageId}");
        }

        // Location 2: woocommerce_response.order.items[0].metadata.eSimDetails[0].insurance
        // Assume woocommerce_response.order.items[0].metadata.eSimDetails already exists
        if (! isset($messageData['woocommerce_response']['order']['items'][0]['metadata']['eSimDetails'][0])) {
            $messageData['woocommerce_response']['order']['items'][0]['metadata']['eSimDetails'][0] = [];
        }

        if (isset($messageData['woocommerce_response']['order']['items'][0]['metadata']['eSimDetails'][0]['insurance'])) {
            $this->comment("  Insurance already exists in items metadata for message {$messageId}. Skipping items injection.");
        } else {
            $messageData['woocommerce_response']['order']['items'][0]['metadata']['eSimDetails'][0]['insurance'] = $insuranceStructure;
            $this->info("  ✓ Insurance injected into items[0].metadata.eSimDetails[0] for message {$messageId}");
        }

        // Save the updated message
        $message->message = $messageData;
        $message->saveOrFail();

        $this->info("  ✓ Message {$messageId} updated successfully with insurance data");
    }
}
