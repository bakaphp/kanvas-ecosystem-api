<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants;

class UpdateVariantsAction
{
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
            $this->variantDto->product->company()->get()->first(),
            $this->user
        );

        if (Variants::where('sku', $this->variantDto->sku)
            ->where('companies_id', $this->variantDto->product->companies_id)
            ->where('id', '!=', $this->variant->getId())
            ->count()
        ) {
            throw new ValidationException('Field sku ' . $this->variantDto->sku . ', already saved');
        }

        $this->variant->update(
            [
                'name' => $this->variantDto->name,
                'slug' => $this->variantDto->slug ?? Str::slug($this->variantDto->name),
                'sku' => $this->variantDto->sku,
                'users_id' => $this->user->getId(),
                'description' => $this->variantDto->description,
                'short_description' => $this->variantDto->short_description,
                'html_description' => $this->variantDto->html_description,
                'status_id' => $this->variantDto->status_id,
                'ean' => $this->variantDto->ean,
                'barcode' => $this->variantDto->barcode,
                'serial_number' => $this->variantDto->serial_number,

            ]
        );
        return $this->variant;
    }
}
