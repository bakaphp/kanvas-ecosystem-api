<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Attributes;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\AddAttributeValue;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\Actions\UpdateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\DataTransferObject\Translate as AttributeTranslateDto;
use Kanvas\Inventory\Attributes\Models\Attributes as AttributeModel;
use Kanvas\Inventory\Attributes\Models\AttributesValues;
use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Languages\Models\Languages;

class AttributeMutation
{
    /**
     * create.
     *
     */
    public function create(mixed $root, array $req): AttributeModel
    {
        $app = app(Apps::class);
        $dto = AttributeDto::viaRequest($req['input'], auth()->user(), $app);
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
     */
    public function update(mixed $root, array $req): AttributeModel
    {
        $app = app(Apps::class);
        $attribute = AttributesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $dto = AttributeDto::viaRequest($req['input'], auth()->user(), $app);
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
     */
    public function delete(mixed $root, array $req): bool
    {
        $attribute = AttributesRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        return $attribute->delete();
    }

    /**
     * update.
     */
    public function updateAttributeTranslation(mixed $root, array $req): AttributeModel
    {
        $company = auth()->user()->getCurrentCompany();
        $language = Languages::getByCode($req['code']);
        $input = $req['input'];
        $attribute = AttributesRepository::getById((int) $req['id'], $company);

        try {
            $attributeTranslateDto = new AttributeTranslateDto(name: $input['name']);
            foreach ($attributeTranslateDto->toArray() as $key => $value) {
                $attribute->setTranslation($key, $language->code, $value);
                $attribute->save();
            }

            if (isset($req['values'])) {
                foreach ($req['values'] as $value => $key) {
                    $attributeValue = AttributesValues::getById((int) $key['id']);
                    $attributeValue->setTranslation('value', $language->code, $key['value']);
                    $attributeValue->save();
                }
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $attribute;
    }
}
