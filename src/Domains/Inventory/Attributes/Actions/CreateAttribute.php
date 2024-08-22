<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\Models\Attributes;

class CreateAttribute
{
    public function __construct(
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
        
            if ($existingAttribute) {
                return $existingAttribute;
            }
        
        return Attributes::firstOrCreate([
            'slug' => $this->dto->slug,
            'companies_id' => $this->dto->company->getId(),
            'apps_id' => $this->dto->app->getId(),
        ], [
            'users_id' => $this->user->getId(),
            'name' => $this->dto->name,
            'attributes_type_id' => $this->dto->attributeType?->getId(),
            'is_visible' => $this->dto->isVisible,
            'is_searchable' => $this->dto->isSearchable,
            'is_filtrable' => $this->dto->isFiltrable,
        ]);
    }
}