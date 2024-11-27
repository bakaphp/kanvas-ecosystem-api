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
use Kanvas\Inventory\Attributes\Actions\AddDefaultAttributeValueToVariant;

class CreateVariantsAction
{
    protected bool $runWorkflow = true;

    /**
     * __construct.
     */
    public function __construct(
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
            $this->variantDto->product->company()->get()->first(),
            $this->user
        );

        $validator = Validator::make(
            ['sku' => $this->variantDto->sku],
            ['sku' => new UniqueSkuRule($this->variantDto->product->app, $this->variantDto->product->company)]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        $search = [
            'products_id' => $this->variantDto->product->getId(),
            'sku' => $this->variantDto->sku,
            'companies_id' => $this->variantDto->product->companies_id,
            'apps_id' => $this->variantDto->product->apps_id,
        ];

        $variant = Variants::updateOrCreate(
            $search,
            [
                'name' => $this->variantDto->name,
                'users_id' => $this->user->getId(),
                'slug' => $this->variantDto->slug ?? Str::slug($this->variantDto->name),
                'description' => $this->variantDto->description,
                'short_description' => $this->variantDto->short_description,
                'html_description' => $this->variantDto->html_description,
                'status_id' => $this->variantDto->status_id,
                'ean' => $this->variantDto->ean,
                'barcode' => $this->variantDto->barcode,
                'serial_number' => $this->variantDto->serial_number,
            ]
        );
        (new AddDefaultAttributeValueToVariant($variant))->execute();

        if ($this->runWorkflow) {
            $variant->product->fireWorkflow(
                WorkflowEnum::UPDATED->value,
                true
            );
        }

        return $variant;
    }
}
