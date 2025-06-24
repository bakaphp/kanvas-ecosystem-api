<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Validator;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Validations\UniqueSkuRule;
use Kanvas\Workflow\Enums\WorkflowEnum;

class DuplicateVariantAction
{
    protected bool $runWorkflow = true;

    /**
     * __construct.
     */
    public function __construct(
        protected Variants $originalVariant,
        protected Products $product,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Variants
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->originalVariant->product->company,
            $this->user
        );

        $duplicateInfo = $this->setDuplicateName();

        $validator = Validator::make(
            ['sku' => $duplicateInfo['sku']],
            ['sku' => new UniqueSkuRule($this->originalVariant->product->app, $this->originalVariant->product->company)]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        $search = [
            'products_id' => $this->product->getId(),
            'sku' => $duplicateInfo['sku'],
            'companies_id' => $this->originalVariant->product->companies_id,
            'apps_id' => $this->originalVariant->product->apps_id,
        ];

        $variant = Variants::updateOrCreate(
            $search,
            [
                'name' => $duplicateInfo['name'],
                'users_id' => $this->user->getId(),
                'slug' => $duplicateInfo['slug'],
                'description' => $this->originalVariant->description,
                'short_description' => $this->originalVariant->short_description,
                'html_description' => ! empty($this->originalVariant->html_description) ? $this->originalVariant->html_description : $this->originalVariant->description,
                'status_id' => $this->originalVariant->status_id,
                'ean' => $this->originalVariant->ean,
                'barcode' => $this->originalVariant->barcode,
                'serial_number' => $this->originalVariant->serial_number,
                'weight' => $this->originalVariant->weight ?? 0,
                'is_published' => $this->originalVariant->is_published,
            ]
        );

        if ($this->runWorkflow) {
            $variant->product->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true
            );
        }

        return $variant;
    }

    public function setDuplicateName()
    {
        $duplicateSku = $this->originalVariant->sku . "(Copy)";

        $originalName = $this->originalVariant->name;
        $originalSlug = $this->originalVariant->slug;
        $originalSku = $this->originalVariant->sku;

        $appId = $this->originalVariant->app->getId();
        $companyId = $this->originalVariant->company->getId();

        // Add "(Copy)" to the original name
        $baseCopyName = $originalName . " (Copy)";
        $baseCopySlug = $originalSlug . "-copy";
        $baseCopySku = $originalSlug . "(Copy)";

        $existingSlugs = Variants::where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('slug', 'like', $baseCopySlug . '%')
            ->pluck('slug')
            ->toArray();

        $counter = 1;
        $duplicateName = $baseCopyName;
        $duplicateSlug = $baseCopySlug;
        $duplicateSku = $baseCopySku;

        if (in_array($duplicateSlug, $existingSlugs)) {
            do {
                $counter++;
                $duplicateName = $originalName . " (Copy {$counter})";
                $duplicateSlug = $originalSlug . "-copy-{$counter}";
                $duplicateSku = $originalSku . "(Copy{$counter})";
            } while (in_array($duplicateSlug, $existingSlugs));
        }

        return [
            'name' => $duplicateName,
            'slug' => $duplicateSlug,
            'sku' => $duplicateSku
        ];
    }
}
