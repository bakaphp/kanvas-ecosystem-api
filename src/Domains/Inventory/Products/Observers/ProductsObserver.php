<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Observers;

use Kanvas\Inventory\Products\Models\Products;

class ProductsObserver
{
    public function saving(Products $product): void
    {
        // If description is empty but html_description has content, update description
        // with the stripped HTML content
        if (empty($product->description) && ! empty($product->html_description)) {
            $html = $product->html_description;
            $html = str_replace(['</p>', '</div>', '<br>', '<br />'], "\n", $html);

            $plainText = \Illuminate\Support\Str::of($html)
                ->stripTags()
                ->replaceMatches('/\n\s+\n/', "\n\n") // Normalize whitespace between paragraphs
                ->replaceMatches('/[\r\n]{3,}/', "\n\n") // Limit consecutive line breaks
                ->trim();

            $product->description = (string) $plainText;
        }
    }

    public function saved(Products $product): void
    {
        if ($product->wasChanged('products_types_id') && $product->productsTypes()->exists()) {
            $product->productsTypes->setTotalProducts();
        }

        $product->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function created(Products $product): void
    {
        if ($product->productsTypes()->exists()) {
            $product->productsTypes->setTotalProducts();
        }

        $product->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
