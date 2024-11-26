<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Enums\WorkflowEnum;

class DeleteVariantsAction
{
    protected bool $runWorkflow = true;

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

        $response = $this->variant->delete();

        if ($this->runWorkflow) {
            $this->variant->fireWorkflow(
                WorkflowEnum::DELETED->value,
                true
            );
        }

        return $response;
    }
}
