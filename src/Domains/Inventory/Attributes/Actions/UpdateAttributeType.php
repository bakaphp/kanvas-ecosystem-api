<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Attributes\DataTransferObject\AttributesType as AttributeTypeDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\Models\AttributesTypes;

class UpdateAttributeType
{
    public function __construct(
        protected AttributesTypes $attributeType,
        protected AttributeTypeDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return Attributes
     */
    public function execute(): AttributesTypes
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        $this->attributeType->update([
            'name' => $this->dto->name,
            'slug' => $this->dto->slug,
            'is_default' => $this->dto->isDefault,
        ]);

        return $this->attributeType;
    }
}
