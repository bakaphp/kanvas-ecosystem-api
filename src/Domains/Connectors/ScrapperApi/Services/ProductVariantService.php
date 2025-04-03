<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Services;

use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;

class ProductVariantService extends ProductService
{
    public function mapVariant(array $product): array
    {
        $codes = $this->getAsinsFromProduct($product['customization_options']);
        $variants = [];
        foreach ($codes as $code) {
            if (! $code) {
                continue;
            }

            $variant = (new ScrapperRepository($this->channels->app))->getByAsin($code);
            $variant['price'] = $variant['pricing'];
            if (key_exists('list_price', $variant)) {
                $variant['original_price'] = [
                    'price' => $variant['list_price'],
                ];
            }
            $variant['image'] = $variant['images'][0];
            $variant['asin'] = $code;
            $variants[] = $this->mapProduct($variant);
        }

        return $variants;
    }

    private function getAsinsFromProduct(array $customizations): array
    {
        $asins = [];
        foreach ($customizations as $key => $value) {
            $asin = array_map(function ($value) {
                if (key_exists('asin', $value)) {
                    return $value['asin'];
                } elseif (key_exists('url', $value)) {
                    if (preg_match('/(?:asin=|dp\/)([A-Z0-9]{10})/', $value['url'], $matches)) {
                        return $matches[1];
                    }
                }
            }, $value);
            $asins = array_merge($asins, $asin);
        }

        return $asins;
    }
}
