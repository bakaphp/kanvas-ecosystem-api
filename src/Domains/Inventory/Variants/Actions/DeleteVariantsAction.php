<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;

class DeleteVariantsAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected Variants $variant,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     */
    public function execute(): bool
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->variant->product->company,
            $this->user
        );

        $totalVariant = Variants::where('companies_id', $this->variant->companies_id)->count();

        if ($totalVariant === 1 && ! $this->variant->is_deleted) {
            throw new ValidationException('There must be at least one variant for each product.');
        }

        return $this->variant->delete();
    }
}
