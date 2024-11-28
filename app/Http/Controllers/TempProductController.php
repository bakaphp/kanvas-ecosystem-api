<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Baka\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Enums\AppEnums;
use Kanvas\Inventory\Products\Models\Products;

class TempProductController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Extract payload from the POST request
        $payload = $request->input('requests', []);
        $paginationParams = $payload[0] ?? []; // Get the first request object

        // Extract pagination parameters
        $hitsPerPage = $paginationParams['hitsPerPage'] ?? 25; // Default to 25 items per page
        $page = $paginationParams['page'] ?? 0; // Default to first page (Algolia's page index is 0-based)

        // Convert Algolia page index to Laravel's (1-based index)
        $currentPage = $page + 1;

        // Fetch and paginate products
        $products = Products::fromApp($app)
            ->notDeleted()
            ->fromCompany($company)
            ->paginate($hitsPerPage, ['*'], 'page', $currentPage);

        // Format the response to mimic Algolia's response structure
        $response = [
            'hits' => collect($products->items())->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'files' => collect($product->files)->map(function ($file) {
                        return [
                            'uuid' => $file->uuid,
                            'name' => $file->name,
                            'url' => $file->url,
                            'size' => $file->size,
                            'field_name' => $file->name,
                            'attributes' => $file->attributes,
                        ];
                    }),
                    'company' => [
                        'id' => $product->company->id,
                        'name' => $product->company->name,
                    ],
                    'user' => [
                        'id' => $product->user->id,
                        'firstname' => $product->user->firstname,
                        'lastname' => $product->user->lastname,
                    ],
                    'categories' => collect($product->categories)->map(function ($category) {
                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                        ];
                    }),
                    'variants' => collect($product->variants)->map(function ($variant) {
                        return [
                            'objectID' => $variant->uuid,
                            'id' => $variant->id,
                            'products_id' => $variant->products_id,
                            'name' => $variant->name,
                            'files' => collect($variant->files)->map(function ($file) {
                                return [
                                    'uuid' => $file->uuid,
                                    'name' => $file->name,
                                    'url' => $file->url,
                                    'size' => $file->size,
                                    'field_name' => $file->name,
                                    'attributes' => $file->attributes,
                                ];
                            }),
                            'company' => [
                                'id' => $variant->company->id,
                                'name' => $variant->company->name,
                            ],
                            'uuid' => $variant->uuid,
                            'slug' => $variant->slug,
                            'sku' => $variant->sku,
                            'status' => [
                                'id' => $variant->status->id ?? null,
                                'name' => $variant->status->name ?? null,
                            ],
                            'warehouses' => collect($variant->warehouses)->map(function ($warehouse) {
                                return [
                                    'id' => $warehouse->id,
                                    'name' => $warehouse->name,
                                    'price' => $warehouse->price,
                                    'quantity' => $warehouse->quantity,
                                    'status' => [
                                        'id' => $warehouse?->status?->id,
                                        'name' => $warehouse?->status?->name,
                                    ],
                                ];
                            }),
                            'channels' => collect($variant->channels)->map(function ($channel) {
                                return [
                                    'id' => $channel->id,
                                    'name' => $channel->name,
                                    'price' => $channel->price,
                                    'is_published' => $channel->is_published,
                                ];
                            }),
                            'attributes' => collect($variant->attributes)->mapWithKeys(function ($attribute) {
                                return [$attribute->name => Str::isJson($attribute->pivot['value']) ? json_decode($attribute->pivot['value'], true) : $attribute->pivot['value']];
                            }),
                            'apps_id' => $variant->apps_id,
                        ];
                    }),
                    'status' => [
                        'id' => $product->status->id ?? null,
                        'name' => $product->status->name ?? null,
                    ],
                    'uuid' => $product->uuid,
                    'slug' => $product->slug,
                    'is_published' => $product->is_published,
                    'description' => $product->description,
                    'short_description' => $product->short_description,
                    'attributes' => collect($product->attributes)->mapWithKeys(function ($attribute) {
                        return [$attribute->name => Str::isJson($attribute->pivot['value']) ? json_decode($attribute->pivot['value'], true) : $attribute->pivot['value']];
                    }),
                    'apps_id' => $product->apps_id,
                    'published_at' => $product->published_at,
                    'created_at' => $product->created_at,
                    '_tags' => [[Products::class . '::' . $product->id]],
                    'objectID' => Products::class . '::' . $product->id,
                    '_highlightResult' => [], // Add highlight logic if needed
                ];
            }),
            'nbHits' => $products->total(),
            'page' => $products->currentPage() - 1,
            'nbPages' => $products->lastPage(),
            'hitsPerPage' => $products->perPage(),
            'exhaustiveNbHits' => true,
            'exhaustiveTypo' => true,
            'exhaustive' => [
                'nbHits' => true,
                'typo' => true,
            ],
            'query' => '',
            'params' => http_build_query($request->query()),
            'index' => $app->get(AppEnums::PRODUCT_VARIANTS_SEARCH_INDEX->getValue()) ?? 'dev-products',
            'renderingContent' => [],
            'processingTimeMS' => 1, // Simulated value
            'serverTimeMS' => 35, // Simulated value
        ];

        return response()->json([
            'results' => [
                $response,
            ],
        ]);
    }
}
