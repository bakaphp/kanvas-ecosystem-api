<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Jobs\IndexProductJob;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class CreateProductAction
{
    protected Apps $app;
    protected bool $runWorkflow = true;

    public function __construct(
        protected ProductDto $productDto,
        protected UserInterface $user,
    ) {
    }

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
                'slug'         => $this->productDto->slug ?? Str::slug($this->productDto->name),
                'apps_id'      => $this->productDto->app->getId(),
                'companies_id' => $this->productDto->company->getId(),
            ];

            $updateData = [
                'products_types_id' => $productType,
                'name'              => $this->productDto->name,
                'description'       => $this->productDto->description,
                'short_description' => $this->productDto->short_description,
                'html_description'  => $this->productDto->html_description,
                'warranty_terms'    => $this->productDto->warranty_terms,
                'upc'               => $this->productDto->upc,
                'status_id'         => $this->productDto->status_id,
                'users_id'          => $this->user->getId(),
                'is_published'      => $this->productDto->is_published,
                'published_at'      => Carbon::now(),
                'weight'            => $this->productDto->weight ?? 0,
            ];

            if ($productType == null) {
                unset($updateData['products_types_id']);
            }
            $products = Products::updateOrCreate(
                $search,
                $updateData
            );

            if (!empty($this->productDto->files)) {
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
                $products->addAttributes($this->productDto->user, $this->productDto->attributes);
            }

            if ($this->productDto->variants) {
                VariantService::createVariantsFromArray($products, $this->productDto->variants, $this->user);
            } else {
                VariantService::createDefaultVariant($products, $this->user, $this->productDto);
            }

            DB::connection('inventory')->commit();

            //IndexProductJob::dispatch($products)->delay(now()->addSeconds(2));
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();

            throw $e;
        }

        if ($products->isPublished()) {
            $products->searchable();
        } else {
            $products->unsearchable();
        }

        if ($this->runWorkflow) {
            $products->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true
            );
        }

        return $products;
    }

    public function setRunWorkflow(bool $runWorkflow): self
    {
        $this->runWorkflow = $runWorkflow;

        return $this;
    }
}
