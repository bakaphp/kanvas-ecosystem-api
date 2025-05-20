<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Validator;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Validations\UniqueSkuRule;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdateVariantsAction
{
    protected bool $runWorkflow = true;

    /**
     * __construct.
     */
    public function __construct(
        protected Variants $variant,
        protected VariantsDto $variantDto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Variants
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->variantDto->product->company,
            $this->user
        );

        $validator = Validator::make(
            ['sku' => $this->variantDto->sku],
            ['sku' => new UniqueSkuRule($this->variant->app, $this->variant->company, $this->variant)]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        $this->variant->update(
            [
                'name' => $this->variantDto->name,
                'slug' => $this->variantDto->slug ?? Str::slug($this->variantDto->name),
                'sku' => $this->variantDto->sku,
                'users_id' => $this->user->getId(),
                'description' => $this->variantDto->getDescription() ?? $this->variant->description,
                'short_description' => $this->variantDto->short_description ?? $this->variant->short_description,
                'html_description' => ! empty($this->variantDto->html_description)
                    ? $this->variantDto->html_description
                    : (! empty($this->variant->html_description)
                        ? $this->variant->html_description
                        : (! empty($this->variantDto->getDescription())
                            ? $this->variantDto->getDescription()
                            : $this->variant->description)),
                'status_id' => $this->variantDto->status_id ?? $this->variant->status_id,
                'ean' => $this->variantDto->ean ?? $this->variant->ean,
                'barcode' => $this->variantDto->barcode ?? $this->variant->barcode,
                'serial_number' => $this->variantDto->serial_number ?? $this->variant->serial_number,
                'weight' => $this->variantDto->weight ?? $this->variant->weight ?? 0,
                'is_published' => $this->variantDto->is_published,
            ]
        );

        //update product searchable index
        $this->variant->product->searchable();

        if ($this->runWorkflow) {
            $this->variant->product->fireWorkflow(
                WorkflowEnum::UPDATED->value,
                true
            );
        }

        return $this->variant;
    }
}
