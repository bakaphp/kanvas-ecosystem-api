<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

class InventoryAgent extends BaseAgent
{
    #[Override]
    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
    ): void {
        if (! $entity instanceof Companies) {
            throw new InvalidArgumentException('Entity must be an instance of Companies');
        }

        $this->agent = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
    }

    #[Override]
    protected function tools(): array
    {
        /** @psalm-suppress MixedReturnTypeCoercion */
        return array_merge(parent::tools(), [
            // Tool for retrieving product information
            Tool::make(
                'get_product_information',
                'Retrieve detailed information about products in inventory. This tool provides real-time information about product details, variants, pricing, stock levels, and images based on search criteria or product ID.',
            )->addProperty(
                new ToolProperty(
                    name: 'search_term',
                    type: 'string',
                    description: 'Search term to find products by name, SKU, or description',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'product_id',
                    type: 'integer',
                    description: 'Specific product ID to retrieve detailed information',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'category',
                    type: 'string',
                    description: 'Filter products by category name',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'limit',
                    type: 'integer',
                    description: 'Maximum number of products to return (default: 5, max: 20)',
                    required: false
                )
            )->setCallable(function (?string $search_term = null, ?int $product_id = null, ?string $category = null, ?int $limit = 5) {
                // Enforce limits to prevent token overflow
                $limit = min(max(1, $limit), 20); // Between 1 and 20

                // Get the current app and company context
                $app = $this->app;
                $companyId = $this->entity->getKey();

                // Initialize query for products
                $productsQuery = Products::where('companies_id', $companyId)
                    ->where('apps_id', $app->getId())
                    ->where('is_deleted', 0)
                    ->where('is_published', 1);

                // If product_id is provided, retrieve specific product
                if ($product_id) {
                    $productsQuery->where('id', $product_id);
                }

                // Apply search term filter if provided
                if ($search_term) {
                    $productsQuery->where(function ($query) use ($search_term) {
                        $query->where('name', 'like', "%{$search_term}%")
                            ->orWhere('description', 'like', "%{$search_term}%")
                            ->orWhere('short_description', 'like', "%{$search_term}%")
                            ->orWhereHas('variants', function ($variantQuery) use ($search_term) {
                                $variantQuery->where('sku', 'like', "%{$search_term}%");
                            });
                    });
                }

                // Apply category filter if provided
                if ($category) {
                    $productsQuery->whereHas('categories', function ($query) use ($category) {
                        $query->where('name', 'like', "%{$category}%");
                    });
                }

                // Get products with limit
                $products = $productsQuery->take($limit)->get();

                // Get default warehouse and channel for pricing information
                $defaultWarehouse = Warehouses::where('companies_id', $companyId)
                    ->where('apps_id', $app->getId())
                    ->where('is_default', 1)
                    ->where('is_deleted', 0)
                    ->first();

                $defaultChannel = Channels::where('companies_id', $companyId)
                    ->where('apps_id', $app->getId())
                    ->where('is_default', 1)
                    ->where('is_deleted', 0)
                    ->first();

                // Prepare response data
                $result = [
                    'total_products_found' => $productsQuery->count(),
                    'products_returned' => $products->count(),
                    'products' => [],
                ];

                foreach ($products as $product) {
                    // Get product attributes
                    $productAttributes = $product->visibleAttributes();
                    $attributesData = [];

                    foreach ($productAttributes as $attribute) {
                        $attributesData[$attribute['name']] = $attribute['value'];
                    }

                    // Get all variants for this product
                    $variants = $product->variants;
                    $variantsData = [];

                    foreach ($variants as $variant) {
                        // Skip deleted or unpublished variants
                        if ($variant->is_deleted || ! $variant->is_published) {
                            continue;
                        }

                        // Get variant attributes
                        $variantAttributes = $variant->visibleAttributes();
                        $variantAttributesData = [];

                        foreach ($variantAttributes as $attribute) {
                            $variantAttributesData[$attribute['name']] = $attribute['value'];
                        }

                        // Get stock availability and pricing
                        $stockQuantity = 0;
                        $price = 0;

                        if ($defaultWarehouse) {
                            $stockQuantity = $variant->getQuantity($defaultWarehouse);
                            $price = $variant->getPrice($defaultWarehouse, $defaultChannel);
                        }

                        // Get variant images
                        $images = $variant->getFiles()->map(function ($file) {
                            return [
                                'id' => $file->id,
                                'url' => $file->url,
                                'name' => $file->name,
                            ];
                        })->toArray();

                        $variantsData[] = [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'sku' => $variant->sku,
                            'price' => $price,
                            'stock_quantity' => $stockQuantity,
                            'in_stock' => $stockQuantity > 0,
                            'attributes' => $variantAttributesData,
                            'images' => $images,
                            'created_at' => $variant->created_at?->format('Y-m-d H:i:s'),
                            'updated_at' => $variant->updated_at?->format('Y-m-d H:i:s'),
                        ];
                    }

                    // Get product images
                    $images = $product->getFiles()->map(function ($file) {
                        return [
                            'id' => $file->id,
                            'url' => $file->url,
                            'name' => $file->name,
                        ];
                    })->toArray();

                    // Get categories
                    $categories = $product->categories->map(function ($category) {
                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                        ];
                    })->toArray();

                    // Add product to result
                    $result['products'][] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'short_description' => $product->short_description,
                        'description' => $product->description,
                        'attributes' => $attributesData,
                        'variants' => $variantsData,
                        'images' => $images,
                        'categories' => $categories,
                        'product_type' => $product->productType ? $product->productType->name : null,
                        'created_at' => $product->created_at?->format('Y-m-d H:i:s'),
                        'updated_at' => $product->updated_at?->format('Y-m-d H:i:s'),
                    ];
                }

                return $result;
            }),

            // Tool for updating product information
            Tool::make(
                'update_product_information',
                'Update existing product information in the inventory system. This tool allows modifications to product details, pricing, stock levels, and other attributes based on the product ID or SKU.',
            )->addProperty(
                new ToolProperty(
                    name: 'identifier',
                    type: 'string',
                    description: 'Product ID or SKU to identify the product for update',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'field',
                    type: 'string',
                    description: 'Field to update (name, description, price, stock, etc.)',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'value',
                    type: 'string',
                    description: 'New value for the specified field',
                    required: true
                )
            )->setCallable(function (string $identifier, string $field, string $value) {
                // Get the current app and company context
                $app = $this->app;
                $companyId = $this->entity->getKey();

                // Determine if identifier is an ID or SKU
                $product = null;
                $variant = null;

                if (is_numeric($identifier)) {
                    // Try to find product by ID
                    $product = Products::where('id', $identifier)
                        ->where('companies_id', $companyId)
                        ->where('apps_id', $app->getId())
                        ->where('is_deleted', 0)
                        ->first();
                } else {
                    // Try to find product by variant SKU
                    $variant = Variants::where('sku', $identifier)
                        ->whereHas('product', function ($query) use ($companyId, $app) {
                            $query->where('companies_id', $companyId)
                                ->where('apps_id', $app->getId())
                                ->where('is_deleted', 0);
                        })
                        ->first();

                    if ($variant) {
                        $product = $variant->product;
                    }
                }

                // If product not found, return error
                if (! $product) {
                    return [
                        'status' => 'error',
                        'message' => 'Product not found with the provided identifier',
                    ];
                }

                // Initialize response
                $result = [
                    'status' => 'success',
                    'product_id' => $product->id,
                    'updated_fields' => [],
                ];

                try {
                    switch (strtolower($field)) {
                        case 'name':
                            $product->name = $value;
                            $product->save();
                            $result['updated_fields']['name'] = $value;

                            break;
                        case 'description':
                            $product->description = $value;
                            $product->save();
                            $result['updated_fields']['description'] = $value;

                            break;
                        case 'short_description':
                            $product->short_description = $value;
                            $product->save();
                            $result['updated_fields']['short_description'] = $value;

                            break;
                        case 'price':
                            // Need to update price on variant
                            if (! $variant) {
                                $variant = $product->variants()->first();
                            }

                            if ($variant) {
                                // Get default warehouse and channel
                                $defaultWarehouse = Warehouses::where('companies_id', $companyId)
                                    ->where('apps_id', $app->getId())
                                    ->where('is_default', 1)
                                    ->where('is_deleted', 0)
                                    ->first();

                                $defaultChannel = Channels::where('companies_id', $companyId)
                                    ->where('apps_id', $app->getId())
                                    ->where('is_default', 1)
                                    ->where('is_deleted', 0)
                                    ->first();

                                if ($defaultWarehouse && $defaultChannel) {
                                    // Update price
                                    $variant->setPrice($defaultWarehouse, $defaultChannel, (float) $value);
                                    $result['updated_fields']['price'] = (float) $value;
                                    $result['variant_id'] = $variant->id;
                                } else {
                                    throw new \Exception('Default warehouse or channel not found');
                                }
                            } else {
                                throw new \Exception('No variant found for this product');
                            }

                            break;
                        case 'stock':
                        case 'quantity':
                        case 'inventory':
                            // Need to update stock on variant
                            if (! $variant) {
                                $variant = $product->variants()->first();
                            }

                            if ($variant) {
                                // Get default warehouse
                                $defaultWarehouse = Warehouses::where('companies_id', $companyId)
                                    ->where('apps_id', $app->getId())
                                    ->where('is_default', 1)
                                    ->where('is_deleted', 0)
                                    ->first();

                                if ($defaultWarehouse) {
                                    // Update stock quantity
                                    $variant->setQuantity($defaultWarehouse, (int) $value);
                                    $result['updated_fields']['stock_quantity'] = (int) $value;
                                    $result['variant_id'] = $variant->id;
                                } else {
                                    throw new \Exception('Default warehouse not found');
                                }
                            } else {
                                throw new \Exception('No variant found for this product');
                            }

                            break;
                        case 'status':
                        case 'published':
                            $isPublished = (strtolower($value) === 'true' || $value === '1' || strtolower($value) === 'yes');
                            $product->is_published = $isPublished;
                            $product->save();

                            // Update all variants as well
                            $product->variants()->update(['is_published' => $isPublished]);

                            $result['updated_fields']['is_published'] = $isPublished;

                            break;
                        case 'category':
                            // Find category by name or ID
                            $category = null;
                            if (is_numeric($value)) {
                                $category = Categories::find($value);
                            } else {
                                $category = Categories::where('name', 'like', "%{$value}%")
                                    ->where('companies_id', $companyId)
                                    ->where('apps_id', $app->getId())
                                    ->first();
                            }

                            if ($category) {
                                // Add category to product
                                $product->categories()->syncWithoutDetaching([$category->id]);
                                $result['updated_fields']['category'] = $category->name;
                                $result['category_id'] = $category->id;
                            } else {
                                throw new \Exception('Category not found: ' . $value);
                            }

                            break;
                        case 'sku':
                            // Need to update SKU on variant
                            if (! $variant) {
                                $variant = $product->variants()->first();
                            }

                            if ($variant) {
                                $variant->sku = $value;
                                $variant->save();
                                $result['updated_fields']['sku'] = $value;
                                $result['variant_id'] = $variant->id;
                            } else {
                                throw new \Exception('No variant found for this product');
                            }

                            break;
                        default:
                            // Try to update as a custom attribute
                            if ($product) {
                                $product->set($field, $value);
                                $result['updated_fields'][$field] = $value;
                                $result['note'] = 'Updated as custom attribute';
                            }

                            break;
                    }

                    // Add timestamp to result
                    $result['updated_at'] = now()->format('Y-m-d H:i:s');

                    return $result;
                } catch (\Exception $e) {
                    Log::error('Product Update Failed', [
                        'product_id' => $product->id,
                        'field' => $field,
                        'value' => $value,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'status' => 'error',
                        'message' => 'Failed to update product: ' . $e->getMessage(),
                    ];
                }
            }),
        ]);
    }
}
