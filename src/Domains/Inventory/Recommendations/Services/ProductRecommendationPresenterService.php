<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Throwable;

/**
 * The one `{ product, variants[] }` shape every path renders through, so the
 * payload cannot drift between Typesense, SQL and the agent.
 */
class ProductRecommendationPresenterService
{
    /** What a search engine must return for document() to render without the database. */
    public const array DOCUMENT_FIELDS = [
        'id',
        'name',
        'slug',
        'files',
        'categories',
        'variants',
        'attributes',
        'apps_id',
        'companies_id',
        'in_stock',
    ];

    private const int MAX_FILES = 10;

    /** The default channel rarely changes; a search must not pay a query for it. */
    private const int CHANNEL_CACHE_TTL = 600;

    private bool $channelResolved = false;
    private ?int $defaultChannelId = null;

    /** @var array<class-string, SupportCollection<int|string, EloquentCollection<int, FilesystemEntities>>> */
    private array $preloadedFiles = [];

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    /**
     * The products behind a list of ids, read back tenant-scoped with everything rendering
     * needs loaded in batches — the database path's only reads. Not fromCompany(): it
     * widens to `companies_id > 0` under an AppKey binding.
     *
     * @param list<int> $ids
     *
     * @return EloquentCollection<int, Products>
     */
    public function loadProducts(array $ids): EloquentCollection
    {
        $query = Products::fromApp($this->app)
            ->notDeleted()
            ->where('is_published', 1)
            ->whereIn('id', array_values(array_unique($ids)))
            ->with([
                'categories',
                'variants.variantChannels.productVariantWarehouse',
                'variants.visibleAttributesRelation',
            ]);

        if (! ProductDiscoverySettings::crossCompanyVariants($this->app)) {
            $query->where('companies_id', $this->company->getId());
        }

        $products = $query->get();
        $this->preload($products);

        return $products;
    }

    /**
     * Loads the files of a page of products and their variants in one query per class,
     * instead of three queries per product and per variant inside files().
     *
     * @param EloquentCollection<int, Products> $products
     */
    private function preload(EloquentCollection $products): void
    {
        $variants = new EloquentCollection($products->flatMap(static fn (Products $product) => $product->variants)->all());

        $this->preloadedFiles[Products::class] = FilesystemEntitiesRepository::getFilesByEntities($products);
        $this->preloadedFiles[Variants::class] = FilesystemEntitiesRepository::getFilesByEntities($variants);
    }

    /** No variants means nothing to render downstream. */
    public function product(Products $product): ?array
    {
        $variants = $product->variants
            ->map(fn (Variants $variant) => $this->variant($variant))
            ->values();

        if ($variants->isEmpty()) {
            return null;
        }

        return [
            'product' => $this->productAttributes($product),
            'variants' => $variants->all(),
        ];
    }

    public function productAttributes(Products $product): array
    {
        return [
            'id' => $product->getId(),
            'slug' => $product->slug,
            'name' => $product->name,
            'files' => $this->files($product),
            'categories' => $product->categories
                ->map(fn (Categories $category) => [
                    'id' => $category->getId(),
                    'name' => $category->name,
                ])
                ->all(),
        ];
    }

    public function variant(Variants $variant): array
    {
        return [
            'id' => $variant->getId(),
            'slug' => $variant->slug,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'description' => $variant->description,
            'attributes' => array_map(
                fn (array $attribute) => [
                    'id' => $attribute['id'] ?? null,
                    'name' => $attribute['name'] ?? null,
                    'value' => $this->stringifyValue($attribute['value'] ?? null),
                ],
                $variant->visibleAttributes(),
            ),
            'channel' => $this->channel($variant),
            'files' => $this->files($variant),
        ];
    }

    /** Out-of-stock and unpriced stay in, flagged unavailable, rather than vanishing. */
    public function channel(Variants $variant): array
    {
        $quantity = $variant->getTotalQuantity();
        $defaultChannelId = $this->resolveDefaultChannelId();

        if ($defaultChannelId === null) {
            return $this->emptyChannel($quantity);
        }

        // Eager-loaded by callers, so this stays O(1) instead of a query per variant.
        $channelInfo = $variant->variantChannels->firstWhere('channels_id', $defaultChannelId);

        if (! $channelInfo) {
            return $this->emptyChannel($quantity);
        }

        $price = (float) $channelInfo->price;
        $discounted = (float) $channelInfo->discounted_price;

        return [
            'price' => $price,
            'discounted_price' => $discounted,
            'is_on_sale' => $discounted > 0 && $discounted < $price,
            'is_available' => $price > 0 && $quantity > 0,
            'quantity' => $quantity,
        ];
    }

    /**
     * The same shape as product(), built from a search engine's document alone. Fields
     * a trimmed or older record lacks degrade to empty rather than failing: a file with
     * no id is skipped, a variant with no indexed quantity falls back to the product's
     * `in_stock`.
     *
     * @param array<string, mixed> $document
     */
    public function document(array $document): ?array
    {
        $channelId = $this->resolveDefaultChannelId();
        $inStock = (bool) ($document['in_stock'] ?? false);

        $variants = array_map(
            fn (array $variant): array => $this->documentVariant($variant, $channelId, $inStock),
            array_values(array_filter((array) ($document['variants'] ?? []), 'is_array')),
        );

        if ($variants === []) {
            return null;
        }

        return [
            'product' => [
                'id' => (int) $document['id'],
                'slug' => (string) ($document['slug'] ?? ''),
                'name' => (string) ($document['name'] ?? ''),
                'files' => $this->documentFiles((array) ($document['files'] ?? [])),
                'categories' => array_values(array_map(
                    static fn (array $category): array => [
                        'id' => (int) ($category['id'] ?? 0),
                        'name' => (string) ($category['name'] ?? ''),
                    ],
                    array_filter((array) ($document['categories'] ?? []), 'is_array'),
                )),
            ],
            'variants' => $variants,
        ];
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function documentVariant(array $variant, ?int $channelId, bool $productInStock): array
    {
        $channel = null;

        foreach ((array) ($variant['channels'] ?? []) as $candidate) {
            if (is_array($candidate) && $channelId !== null && (int) ($candidate['id'] ?? 0) === $channelId) {
                $channel = $candidate;

                break;
            }
        }

        $quantity = isset($variant['quantity']) ? (int) $variant['quantity'] : null;
        $price = $channel !== null ? (float) ($channel['price'] ?? 0) : null;
        $discounted = $channel !== null ? (float) ($channel['discounted_price'] ?? 0) : null;
        $inStock = $quantity !== null ? $quantity > 0 : $productInStock;

        $attributes = [];
        foreach ((array) ($variant['attributes'] ?? []) as $name => $value) {
            $attributes[] = [
                'id' => null,
                'name' => (string) $name,
                'value' => $this->stringifyValue($value),
            ];
        }

        return [
            'id' => (int) ($variant['id'] ?? 0),
            'slug' => $variant['slug'] ?? null,
            'name' => (string) ($variant['name'] ?? ''),
            'sku' => $variant['sku'] ?? null,
            'description' => null,
            'attributes' => $attributes,
            'channel' => $channel === null ? $this->emptyChannel($quantity ?? 0) : [
                'price' => $price,
                'discounted_price' => $discounted,
                'is_on_sale' => $discounted > 0 && $discounted < $price,
                'is_available' => $price > 0 && $inStock,
                'quantity' => $quantity ?? ($inStock ? 1 : 0),
            ],
            'files' => $this->documentFiles((array) ($variant['files'] ?? [])),
        ];
    }

    /**
     * @param array<int|string, mixed> $files
     */
    private function documentFiles(array $files): array
    {
        $rendered = [];

        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['id'], $file['url'])) {
                continue;
            }

            $rendered[] = [
                'id' => (int) $file['id'],
                'url' => (string) $file['url'],
                'name' => $file['name'] ?? null,
            ];

            if (count($rendered) >= self::MAX_FILES) {
                break;
            }
        }

        return $rendered;
    }

    public function files(Products|Variants $entity): array
    {
        $files = [];
        $source = isset($this->preloadedFiles[$entity::class])
            ? $this->preloadedFiles[$entity::class]->get($entity->getKey(), collect())
            : $entity->getFiles();

        foreach ($source->take(self::MAX_FILES) as $file) {
            $files[] = [
                'id' => $file->getId(),
                'url' => $file->url,
                'name' => $file->name,
            ];
        }

        return $files;
    }

    public function resolveDefaultChannelId(): ?int
    {
        if ($this->channelResolved) {
            return $this->defaultChannelId;
        }

        $this->channelResolved = true;

        // 0 stands for "this company has no default channel": Cache::remember() never stores a
        // null, so caching the miss as null would re-query on every search.
        $channelId = (int) Cache::remember(
            'product-discovery:default-channel:' . $this->app->getId() . ':' . $this->company->getId(),
            self::CHANNEL_CACHE_TTL,
            function (): int {
                try {
                    return (int) Channels::getDefault($this->company, $this->app)->getId();
                } catch (Throwable) {
                    return 0;
                }
            },
        );

        return $this->defaultChannelId = $channelId > 0 ? $channelId : null;
    }

    private function emptyChannel(int $quantity = 0): array
    {
        return [
            'price' => null,
            'discounted_price' => null,
            'is_on_sale' => false,
            'is_available' => false,
            'quantity' => $quantity,
        ];
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
