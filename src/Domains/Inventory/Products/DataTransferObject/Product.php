<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Contracts\Container\BindingResolutionException;
use Kanvas\Apps\Models\Apps;
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
    public static function viaRequest(array $request, CompanyInterface $company): self
    {
        return new self(
            app(Apps::class),
            $company,
            auth()->user(),
            $request['name'],
            $request['description'] ?? null,
            isset($request['products_types_id']) ? ProductsTypesRepository::getById((int) $request['products_types_id'], $company) : null,
            $request['short_description'] ?? null,
            $request['html_description'] ?? null,
            $request['warranty_terms'] ?? null,
            $request['upc'] ?? null,
            $request['status_id'] ?? null,
            $request['is_published'] ?? true,
            $request['sku'] ?? null,
            $request['categories'] ?? [],
            $request['warehouses'] ?? [],
            $request['variants'] ?? [],
            $request['attributes'] ?? [],
            $request['productType'] ?? [],
            $request['files'] ?? [],
            $request['slug'] ?? null,
            $request['weight'] ?? null
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
