<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Services\VariantService;
use Throwable;

class CreateProductAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected ProductDto $productDto,
        protected UserInterface $user,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Products
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->productDto->company,
            $this->user
        );

        try {
            DB::connection('inventory')->beginTransaction();

            $productType = $this->productDto?->productsType?->getId();

            $search = [
                'slug' => $this->productDto->slug ?? Str::slug($this->productDto->name),
                'apps_id' => $this->productDto->app->getId(),
                'companies_id' => $this->productDto->company->getId(),
            ];
            $products = Products::updateOrCreate(
                $search,
                [
                    'products_types_id' => $productType,
                    'name' => $this->productDto->name,
                    'description' => $this->productDto->description,
                    'short_description' => $this->productDto->short_description,
                    'html_description' => $this->productDto->html_description,
                    'warranty_terms' => $this->productDto->warranty_terms,
                    'upc' => $this->productDto->upc,
                    'status_id' => $this->productDto->status_id,
                    'users_id' => $this->user->getId(),
                    'is_published' => $this->productDto->is_published,
                    'published_at' => Carbon::now(),
                ]
            );

            if (! empty($this->productDto->files)) {
                $products->addMultipleFilesFromUrl($this->productDto->files);
            }

            if ($this->productDto->categories) {
                foreach ($this->productDto->categories as $category) {
                    $category = CategoriesRepository::getById((int) $category['id'], $this->productDto->company);
                    (new AddCategoryAction(
                        $products,
                        $category
                    ))->execute();
                }
            }

            if ($this->productDto->attributes) {
                foreach ($this->productDto->attributes as $attribute) {
                    if (isset($attribute['id'])) {
                        $attributeModel = Attributes::getById((int) $attribute['id'], $products->app);
                    } else {
                        $attributesDto = AttributesDto::from([
                            'app' => $this->productDto->app,
                            'user' => $this->user,
                            'company' => $this->productDto->company,
                            'name' => $attribute['name'],
                            'isVisible' => false,
                            'isSearchable' => false,
                            'isFiltrable' => false,
                            'slug' => Str::slug($attribute['name'])
                        ]);

                        $attributeModel = (new CreateAttribute($attributesDto, $this->user))->execute();
                    }
                    (new AddAttributeAction($products, $attributeModel, $attribute['value']))->execute();
                }
            }

            if ($this->productDto->variants) {
                VariantService::createVariantsFromArray($products, $this->productDto->variants, $this->user);
            } else {
                VariantService::createDefaultVariant($products, $this->user, $this->productDto);
            }

            DB::connection('inventory')->commit();
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();

            throw $e;
        }

        return $products;
    }
}
