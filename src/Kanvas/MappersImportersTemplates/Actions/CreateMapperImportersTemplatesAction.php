<?php

declare(strict_types=1);

namespace Kanvas\MappersImportersTemplates\Actions;

use Kanvas\MappersImportersTemplates\Models\MapperImportersTemplates;
use Kanvas\MappersImportersTemplates\DataTransferObject\MapperImportersTemplates as MapperImportersTemplatesDto;

class CreateMapperImportersTemplatesAction
{
    public function __construct(protected MapperImportersTemplatesDto $data)
    {
    }

    public function execute(): MapperImportersTemplates
    {
        $importersTemplates = MapperImportersTemplates::create([
            'apps_id' => $this->data->apps->getId(),
            'users_id' => $this->data->users->getId(),
            'companies_id' => $this->data->companies->getId(),
            'name' => $this->data->name,
            'description' => $this->data->description
        ]);
        $this->createAttributes($importersTemplates, $this->data->attributes);
        return $importersTemplates;
    }

    protected function createAttributes(MapperImportersTemplates $importersTemplates, array $attributes, int $parentId = 0): void
    {
        foreach ($attributes as $attribute) {
            $model = $importersTemplates->attributes()->create([
                'name' => $attribute['name'],
                'value' => $attribute['value'],
                'parent_id' => $parentId
            ]);
            if (isset($attribute['children'])) {
                $this->createAttributes($importersTemplates, $attribute['children'], $model->id);
            }
        }
    }
}
