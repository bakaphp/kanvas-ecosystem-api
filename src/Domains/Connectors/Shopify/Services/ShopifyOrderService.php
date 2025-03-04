<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use PHPShopify\ShopifySDK;

class ShopifyOrderService
{
    protected ShopifySDK $shopifySdk;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Warehouses $warehouses,
    ) {
        $this->shopifySdk = Client::getInstance($this->app, $this->company, $this->warehouses->regions);
    }

    /**
     * Add a comment or note to a Shopify order without overwriting existing values.
     *
     * @param array $noteAttributes Additional attributes if needed
     *
     * @return array The updated order response
     */
    public function addNoteToOrder(int|string $orderId, string $note, array $noteAttributes = []): array
    {
        $order = $this->shopifySdk->Order($orderId)->get();

        $updatedNote = trim(($order['note'] ?? '') . "\n" . $note);

        // Convert existing attributes to associative array
        $existingNoteAttributesAssoc = array_column($order['note_attributes'] ?? [], 'value', 'name');

        // Merge new attributes
        $updatedNoteAttributes = array_merge($existingNoteAttributesAssoc, $noteAttributes);

        // Convert back to indexed array
        $formattedNoteAttributes = array_map(fn ($name, $value) => ['name' => $name, 'value' => $value], array_keys($updatedNoteAttributes), $updatedNoteAttributes);

        if (strlen($updatedNote) > 5000) {
            //trim to avoid   note - is too long (maximum is 5000 characters)
            $updatedNote = substr($updatedNote, 0, 4999);
        }

        $payload = [
            'note' => $updatedNote,
            'note_attributes' => $formattedNoteAttributes,
        ];

        return $this->shopifySdk->Order($orderId)->put($payload);
    }
}
