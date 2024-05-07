<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Attributes;

use Kanvas\Inventory\Attributes\Actions\AddAttributeValue;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\Actions\UpdateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\Models\Attributes as AttributeModel;
use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;

class AttributeMutation
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

        if (isset($req['input']['values'])) {
            (new AddAttributeValue($attributeModel, $req['input']['values']))->execute();
        }

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
        $attribute = AttributesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $dto = AttributeDto::viaRequest($req['input']);
        (new UpdateAttribute($attribute, $dto, auth()->user()))->execute();

        if (isset($req['input']['values'])) {
            $attribute->defaultValues()->delete();
            (new AddAttributeValue($attribute, $req['input']['values']))->execute();
        }
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
        $attribute = AttributesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        return $attribute->delete();
    }
}
