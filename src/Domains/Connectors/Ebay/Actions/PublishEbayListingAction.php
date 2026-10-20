<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ebay\Actions;

use Kanvas\Connectors\Ebay\Client;
use Kanvas\Connectors\Ebay\DataTransferObject\EbayListingDto;
use Kanvas\Connectors\Ebay\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

final class PublishEbayListingAction
{
    /** @param array<string, mixed> $params */
    public function __construct(
        private Products|Variants $entity,
        private array $params = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(): array
    {
        $company = $this->entity->company;
        $app = $this->entity->app;
        if ($company === null || $app === null) {
            throw new ValidationException('eBay listing requires a product company and app');
        }

        $client = new Client($app, $company);
        $listing = EbayListingDto::fromEntity($this->entity, $this->params);
        $client->resolveAccessToken();

        $client->createOrReplaceInventoryItem($listing->sku, $listing->inventoryItem());
        $existingOfferId = $this->entity->get(CustomFieldEnum::EBAY_OFFER_ID->value);
        if (is_string($existingOfferId) && $existingOfferId !== '') {
            $offerId = $existingOfferId;
            $client->updateOffer($offerId, $listing->offer());
        } else {
            $offer = $client->createOffer($listing->offer());
            $offerId = $offer['offerId'] ?? null;
        }

        if (! is_string($offerId) || $offerId === '') {
            throw new ValidationException('eBay did not return an offer ID');
        }

        $this->entity->set(CustomFieldEnum::EBAY_OFFER_ID->value, $offerId);
        $published = $client->publishOffer($offerId);
        $listingId = $published['listingId'] ?? null;
        if (! is_string($listingId) || $listingId === '') {
            throw new ValidationException('eBay did not return a listing ID');
        }

        $listingUrl = $client->listingUrl($listingId);
        $this->entity->set(CustomFieldEnum::EBAY_LISTING_ID->value, $listingId);
        $this->entity->set(CustomFieldEnum::EBAY_LISTING_URL->value, $listingUrl);

        return [
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'listing_url' => $listingUrl,
            'sku' => $listing->sku,
            'response' => $published,
        ];
    }
}
