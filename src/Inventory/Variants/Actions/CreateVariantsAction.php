<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants;

class CreateVariantsAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected VariantsDto $variantDto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return Variants
     */
    public function execute() : Variants
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->variantDto->product->company()->get()->first(),
            $this->user
        );

        $search = [
            'products_id' => $this->variantDto->product->getId(),
            'name' => trim($this->variantDto->name),
            'companies_id' => $this->variantDto->product->companies_id,
            'apps_id' => $this->variantDto->product->apps_id
        ];

        if ($this->variantDto->slug) {
            unset($search['name']);
            $search['slug'] = $this->variantDto->slug;
        }

        return Variants::firstOrCreate(
            $search,
            [
                'name' => $this->variantDto->name,
                'users_id' => $this->user->getId(),
                'description' => $this->variantDto->description,
                'short_description' => $this->variantDto->short_description,
                'html_description' => $this->variantDto->html_description,
                'sku' => $this->variantDto->sku,
                'ean' => $this->variantDto->ean,
                'barcode' => $this->variantDto->barcode,
                'serial_number' => $this->variantDto->serial_number,
                'is_published' => $this->variantDto->is_published
            ]
        );
    }
}
