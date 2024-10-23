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

        return $this->variant->delete();
    }
}
