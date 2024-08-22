<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\Models\Attributes;

class UpdateAttribute
{
    public function __construct(
        protected Attributes $attribute,
        protected AttributeDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return Attributes
     */
    public function execute(): Attributes
    {   
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );
        
        $existingAttribute = Attributes::getBySlug(
            $this->dto->slug,
            $this->dto->company,
            $this->dto->app->getId()
        );

        $slug = ($existingAttribute && $existingAttribute->id !== $this->attribute->id)
            ? $this->attribute->slug
            : $this->dto->slug;

        $this->attribute->update([
            'slug' => $slug,
            'name' => $this->dto->name,
            'attributes_type_id' => $this->dto->attributeType?->getId(),
            'is_visible' => $this->dto->isVisible,
            'is_searchable' => $this->dto->isSearchable,
            'is_filtrable' => $this->dto->isFiltrable,
        ]);

        return $this->attribute;
    }
}
