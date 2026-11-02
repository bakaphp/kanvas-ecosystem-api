<?php

declare(strict_types=1);

namespace Kanvas\Souk\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Enums\ConfigurationEnum;

/**
 * Resolves the template once per store, so a tool presenting a page of products pays for the
 * settings lookup once rather than per row.
 */
class StorefrontProductUrlService
{
    private readonly ?string $template;

    public function __construct(
        CompanyInterface $company,
        AppInterface $app
    ) {
        $key = ConfigurationEnum::STOREFRONT_PRODUCT_URL->value;

        $this->template = Str::trimToNull((string) ($company->get($key) ?? $app->get($key) ?? ''));
    }

    public static function forProduct(Products $product): self
    {
        return new self($product->company, $product->app);
    }

    public function productUrl(Products $product): ?string
    {
        return $this->urlFor((int) $product->getId(), (string) $product->slug);
    }

    /** For a caller holding a search document rather than a model. */
    public function urlFor(int $id, string $slug): ?string
    {
        if ($this->template === null) {
            return null;
        }

        if (! str_contains($this->template, '{')) {
            return rtrim($this->template, '/') . '/' . $slug;
        }

        return str_replace(
            ['{slug}', '{id}'],
            [$slug, (string) $id],
            $this->template
        );
    }
}
