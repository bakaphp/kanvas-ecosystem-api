<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Enums\AppEnums;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum;

class VariantPriceService
{
    protected bool $useCompanySpecificPrice = false;
    protected ?Channels $currentChannel = null;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $currentUserCompany = null
    ) {
        $this->currentUserCompany = $currentUserCompany;
        $this->useCompanySpecificPrice = (bool) ($app->get(ConfigurationEnum::COMPANY_CUSTOM_CHANNEL_PRICING->value) ?? false);
    }

    public function getPrice(Variants $variant, ?int $channelId = null): float
    {
        // Reset channel before each price calculation
        $this->currentChannel = null;

        try {
            if ($this->useCompanySpecificPrice && $this->currentUserCompany) {
                $companyPrice = $this->getCompanySpecificPrice($variant);

                // If company-specific price is 0, fall back to channel price
                if ($companyPrice > 0) {
                    return $companyPrice;
                }
            }

            return $this->getChannelPrice($variant, $channelId);
        } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            return $this->getFallbackPrice($variant);
        }
    }

    /**
     * This is the logic to get the company specific price
     * of this variant for the channel that has the slug of the current user company id
     * what is this for? for b2b where you have specific prices for each company
     * not the best solution , @todo discuss if use inventory per company
     */
    private function getCompanySpecificPrice(Variants $variant): float
    {
        $variantChannel = $variant->variantChannels()
             ->whereHas('channel', function ($query) {
                 $query->where('slug', $this->currentUserCompany->uuid);
             })
             ->firstOrFail();

        $this->setCurrentChannel($variantChannel->channel);

        return (float) $variantChannel->price;
    }

    private function getChannelPrice(Variants $variant, ?int $channelId = null): float
    {
        $channel = null;

        if ($channelId === null) {
            $channel = Channels::getDefault($variant->company, $variant->app);
            $channelId = $channel?->getId();
        } else {
            $channel = Channels::getById($channelId, $this->app);
        }

        $this->setCurrentChannel($channel);

        if ($variant->app->get(AppEnums::CAN_USE_COMMERCE_DISCOUNT_PRICE->getValue())) {
            // Try to get the discount price if available and greater than 0
            $discountedPrice = $variant->channels()
                ->where('channels_id', $channelId)
                ->value('discounted_price');

            if ($discountedPrice !== null && $discountedPrice > 0) {
                return (float) $discountedPrice;
            }
        }

        // Try to get channel-specific price
        $channelPrice = $variant->channels()
            ->where('channels_id', $channelId)
            ->value('price'); // Gets the price directly instead of the whole object

        return $channelPrice ? (float) $channelPrice : $this->getDefaultChannelPrice($variant);
    }

    private function getDefaultChannelPrice(Variants $variant): float
    {
        $variantChannel = $variant->getPriceInfoFromDefaultChannel();
        $this->setCurrentChannel($variantChannel->channel);

        return (float) $variantChannel->price;
    }

    private function getInventoryPrice(Variants $variant): float
    {
        // No channel associated with warehouse pricing
        $this->currentChannel = null;

        return $variant->variantWarehouses()->first()->price ?? 0.00;
    }

    private function getFallbackPrice(Variants $variant): float
    {
        try {
            $defaultPrice = $this->getDefaultChannelPrice($variant);
            if ($defaultPrice > 0) {
                return $defaultPrice;
            }
        } catch (ModelNotFoundException|ExceptionsModelNotFoundException) {
            // Default channel price failed, continue to inventory price
        }

        return $this->getInventoryPrice($variant);
    }

    protected function setCurrentChannel(?Channels $channel): void
    {
        $this->currentChannel = $channel;
    }

    public function getCurrentChannel(): ?Channels
    {
        return $this->currentChannel;
    }

    public function getCurrentChannelId(): ?int
    {
        return $this->currentChannel?->getId();
    }
}
