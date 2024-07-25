<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Attributes;

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
     * @param  mixed $root
     * @param  array $req
     *
     * @return AttributeModel
     */
    public function create(mixed $root, array $req): AttributesTypesModel
    {
        $dto = AttributesType::viaRequest($req['input']);
        $action = new CreateAttributeType($dto, auth()->user());
        $attributeTypeModel = $action->execute();

        return $attributeTypeModel;
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return AttributeModel
     */
    public function update(mixed $root, array $req): AttributesTypesModel
    {
        $attribute = AttributesTypesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $dto = AttributesType::viaRequest($req['input']);
        (new UpdateAttributeType($attribute, $dto, auth()->user()))->execute();

        return $attribute;
    }

    /**
     * delete.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function delete(mixed $root, array $req): bool
    {
        $attributeType = AttributesTypesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        return $attributeType->delete();
    }
}
