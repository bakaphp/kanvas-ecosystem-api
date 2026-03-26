<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Observers;

use Illuminate\Support\Facades\Redis;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;

class VariantObserver
{
    public function saved(Variants $variant): void
    {
        $variant->product->clearLightHouseCache(withKanvasConfiguration: false);
        $variant->clearLightHouseCache(withKanvasConfiguration: false);
        $this->clearVariantChannelCache($variant);

        if ($variant->app->get('product_increase_weight_by_image_count')) {
            $variant->product->weight += 0.5;
            $variant->product->save();
        }
    }

    public function deleting(Variants $variant): void
    {
        $totalVariant = Variants::fromCompany($variant->company)
        ->fromApp($variant->app)
        ->where('products_id', $variant->products_id)
        ->count();

        if ($totalVariant === 1 && ! $variant->is_deleted) {
            throw new ValidationException('There must be at least one variant for each product.');
        }
    }

    public function deleted(Variants $variant): void
    {
        $variant->product->clearLightHouseCache(withKanvasConfiguration: false);
        $this->clearVariantChannelCache($variant);
    }

    private function clearVariantChannelCache(Variants $variant): void
    {
        $redis = Redis::connection('graph-cache');
        $hashKey = CacheKeyAndTagsGenerator::PREFIX . ':VariantChannel:' . $variant->getId();
        $redis->del($hashKey);
    }
}
