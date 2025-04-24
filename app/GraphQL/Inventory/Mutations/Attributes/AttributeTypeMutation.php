<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Attributes;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\CreateAttributeType;
use Kanvas\Inventory\Attributes\Actions\UpdateAttributeType;
use Kanvas\Inventory\Attributes\DataTransferObject\AttributesType;
use Kanvas\Inventory\Attributes\Models\Attributes as AttributeModel;
use Kanvas\Inventory\Attributes\Models\AttributesTypes as AttributesTypesModel;
use Kanvas\Inventory\Attributes\Repositories\AttributesTypesRepository;

class AttributeTypeMutation
{
    /**
     * create.
     *
     * @return AttributeModel
     */
    public function create(mixed $root, array $req): AttributesTypesModel
    {
        $app = app(Apps::class);

        $dto = AttributesType::viaRequest($req['input'], auth()->user(), $app);
        $action = new CreateAttributeType($dto, auth()->user());
        $attributeTypeModel = $action->execute();

        return $attributeTypeModel;
    }

    /**
     * update.
     *
     * @return AttributeModel
     */
    public function update(mixed $root, array $req): AttributesTypesModel
    {
        $app = app(Apps::class);

        $attribute = AttributesTypesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $dto = AttributesType::viaRequest($req['input'], auth()->user(), $app);
        (new UpdateAttributeType($attribute, $dto, auth()->user()))->execute();

        return $attribute;
    }

    /**
     * delete.
     *
     */
    public function delete(mixed $root, array $req): bool
    {
        $attributeType = AttributesTypesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        return $attributeType->delete();
    }
}
