<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Attributes;

use Kanvas\Inventory\Attributes\Actions\AddAttributeValue;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\Models\Attributes as AttributeModel;
use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;

class Attributes
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return AttributeModel
     */
    public function create(mixed $root, array $req): AttributeModel
    {
        $dto = AttributeDto::viaRequest($req['input']);
        $action = new CreateAttribute($dto, auth()->user());
        $attributeModel = $action->execute();

        (new AddAttributeValue($attributeModel, $dto->value))->execute();

        return $attributeModel;
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return AttributeModel
     */
    public function update(mixed $root, array $req): AttributeModel
    {
        $attribute = AttributesRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $attribute->update($req['input']);
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
        $attribute = AttributesRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        return $attribute->delete();
    }
}
