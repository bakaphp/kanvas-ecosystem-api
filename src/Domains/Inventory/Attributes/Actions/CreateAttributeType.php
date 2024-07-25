<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Attributes\DataTransferObject\AttributesType;
use Kanvas\Inventory\Attributes\Models\AttributesTypes;

class CreateAttributeType
{
    public function __construct(
        protected AttributesType $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return AttributesTypes
     */
    public function execute(): AttributesTypes
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        return AttributesTypes::firstOrCreate([
            'name' => $this->dto->name,
            'companies_id' => $this->dto->company->getId(),
            'apps_id' => $this->dto->app->getId(),
        ], [
            'slug' => $this->dto->slug,
            'is_default' => $this->dto->isDefault,
        ]);
    }
}
