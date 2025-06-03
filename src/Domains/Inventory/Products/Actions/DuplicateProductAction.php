<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Actions\DuplicateVariantAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class DuplicateProductAction
{
    protected Apps $app;
    protected bool $runWorkflow = true;

    public function __construct(
        protected Products $originalProduct,
        protected UserInterface $user,
    ) {
    }

    public function execute(): Products
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->originalProduct->company,
            $this->user
        );

        try {
            DB::connection('inventory')->beginTransaction();

            $productType = $this->originalProduct?->productsType?->getId();
            $duplicateInfo = $this->setDuplicateName();

            // $duplicateName = $this->originalProduct->name." (Copy)";
            $search = [
                'slug' => $duplicateInfo['slug'],
                'apps_id' => $this->originalProduct->app->getId(),
                'companies_id' => $this->originalProduct->company->getId(),
            ];

            $duplicatedProduct = [
                'products_types_id' => $productType,
                'name' => $duplicateInfo['name'],
                'description' => $this->originalProduct->description,
                'short_description' => $this->originalProduct->short_description,
                'html_description' => ! empty($this->originalProduct->html_description) ? $this->originalProduct->html_description : $this->originalProduct->description,
                'warranty_terms' => $this->originalProduct->warranty_terms,
                'upc' => $this->originalProduct->upc,
                'status_id' => $this->originalProduct->status_id,
                'users_id' => $this->user->getId(),
                'is_published' => $this->originalProduct->is_published,
                'published_at' => Carbon::now(),
                'weight' => $this->originalProduct->weight ?? 0,
            ];

            if ($productType == null) {
                unset($duplicatedProduct['products_types_id']);
            }

            // We already create an unique slug, so this line is for security.
            $existingProduct = Products::where($search)->first();

            // Use the existing product if found, otherwise create a new one
            if ($existingProduct) {
                $existingProduct->update($duplicatedProduct);
                $products = $existingProduct;
            } else {
                $products = Products::create(array_merge($search, $duplicatedProduct));
            }

            if ($this->originalProduct->categories) {
                foreach ($this->originalProduct->categories as $category) {
                    $category = CategoriesRepository::getById((int) $category['id'], $this->originalProduct->company);
                    (new AddCategoryAction(
                        $products,
                        $category
                    ))->execute();
                }
            }

            if ($this->originalProduct->attributes) {
                $products->addAttributes($this->originalProduct->user, $this->originalProduct->attributes->toArray());
            }


            foreach ($this->originalProduct->variants as $variant) {
                (new DuplicateVariantAction(
                    $variant,
                    $products,
                    $this->user
                ))->execute();
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

    public function setDuplicateName()
    {
        $originalName = $this->originalProduct->name;
        $originalSlug = $this->originalProduct->slug;
        $appId = $this->originalProduct->app->getId();
        $companyId = $this->originalProduct->company->getId();

        $baseCopyName = $originalName . " (Copy)";
        $baseCopySlug = $originalSlug . "-copy";

        $existingSlugs = Products::where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('slug', 'like', $baseCopySlug . '%')
            ->pluck('slug')
            ->toArray();

        $counter = 1;
        $duplicateName = $baseCopyName;
        $duplicateSlug = $baseCopySlug;

        // search the next slug if existed already, yeah a do while
        if (in_array($duplicateSlug, $existingSlugs)) {
            do {
                $counter++;
                $duplicateName = $originalName . " (Copy {$counter})";
                $duplicateSlug = $originalSlug . "-copy-{$counter}";
            } while (in_array($duplicateSlug, $existingSlugs));
        }

        return [
            'name' => $duplicateName,
            'slug' => $duplicateSlug
        ];
    }
}
