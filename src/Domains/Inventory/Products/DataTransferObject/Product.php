<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Contracts\Container\BindingResolutionException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Spatie\LaravelData\Data;

class Product extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public CompanyInterface $company,
        public UserInterface $user,
        public string $name,
        public ?string $description = null,
        public ?ProductsTypes $productsType = null,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $warranty_terms = null,
        public ?string $upc = null,
        public ?int $status_id = null,
        public bool $is_published = true,
        public ?string $sku = null,

        //@var array<int>
        public array $categories = [],
        public array $warehouses = [],
        public array $variants = [],
        public array $attributes = [],
        public array $productType = [],
        public array $files = [],
        public ?string $slug = null,
        public ?float $weight = null,
    ) {
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     * @throws BindingResolutionException
     * @throws ModelNotFoundException
     */
    public static function fromMultiple(array $request, CompanyInterface $company, AppInterface $app, UserInterface $user): self
    {
        return new self(
            app: $app,
            company: $company,
            user: $user,
            name: $request['name'],
            description: $request['description'] ?? null,
            productsType: isset($request['products_types_id']) ? ProductsTypesRepository::getById((int) $request['products_types_id'], $company) : null,
            short_description: $request['short_description'] ?? null,
            html_description: $request['html_description'] ?? null,
            warranty_terms: $request['warranty_terms'] ?? null,
            upc: $request['upc'] ?? null,
            status_id: $request['status_id'] ?? null,
            is_published: $request['is_published'] ?? true,
            sku: $request['sku'] ?? null,
            categories: $request['categories'] ?? [],
            warehouses: $request['warehouses'] ?? [],
            variants: $request['variants'] ?? [],
            attributes: $request['attributes'] ?? [],
            productType: $request['productType'] ?? [],
            files: $request['files'] ?? [],
            slug: $request['slug'] ?? null,
            weight: $request['weight'] ?? null
        );
    }

    public function getDescription(): ?string
    {
        if (empty($this->description) && ! empty($this->html_description)) {
            $html = $this->html_description;
            $html = str_replace(['</p>', '</div>', '<br>', '<br />'], "\n", $html);

            $plainText = Str::of($html)
                ->stripTags()
                ->replaceMatches('/\n\s+\n/', "\n\n") // Normalize whitespace between paragraphs
                ->replaceMatches('/[\r\n]{3,}/', "\n\n") // Limit consecutive line breaks
                ->trim();

            $this->description = (string) $plainText;
        }

        return $this->description;
    }
}
