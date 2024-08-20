<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Support\Validations\UniqueSlugRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
        $validator = Validator::make(
            ['slug' => $this->dto->slug],
            ['slug' => [new UniqueSlugRule($this->dto->app, $this->dto->company, $this->attribute)]]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

            CompaniesRepository::userAssociatedToCompany(
                $this->dto->company,
                $this->user
        );

        $this->attribute->update([
            'name' => $this->dto->name,
            'attributes_type_id' => $this->dto->attributeType?->getId(),
            'is_visible' => $this->dto->isVisible,
            'is_searchable' => $this->dto->isSearchable,
            'is_filtrable' => $this->dto->isFiltrable,
            'slug' => $this->dto->slug,
        ]);

        return $this->attribute;
    }
}
