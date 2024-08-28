<?php

declare(strict_types=1);

namespace Kanvas\MappersImportersTemplates\Actions;

use Kanvas\MappersImportersTemplates\Models\MapperImporterTemplate;
use Kanvas\MappersImportersTemplates\DataTransferObject\MapperImporterTemplate as MapperImportersTemplatesDto;
use Kanvas\MappersImportersTemplates\Models\AttributeMapperImporterTemplate;

class CreateMapperImporterTemplateAction
{
    public function __construct(protected MapperImportersTemplatesDto $data)
    {
    }

    public function execute(): MapperImporterTemplate
    {
        $importersTemplates = MapperImporterTemplate::create([
            'apps_id' => $this->data->apps->getId(),
            'users_id' => $this->data->users->getId(),
            'companies_id' => $this->data->companies->getId(),
            'name' => $this->data->name,
            'description' => $this->data->description
        ]);
        $this->createAttributes($importersTemplates, $this->data->attributes);
        return $importersTemplates;
    }

    protected function createAttributes(MapperImporterTemplate $importersTemplates, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $root = new AttributeMapperImporterTemplate();
            $root->importers_templates_id = $importersTemplates->id;
            $root->name = $attribute['name'];
            $root->mapping_field = key_exists("mapping_field", $attribute) ? $attribute['mapping_field'] : $attribute['name'];
            $root->save();

            if (isset($attribute['children'])) {
                foreach ($attribute['children'] as $child) {
                    $childModel = new AttributeMapperImporterTemplate();
                    $childModel->importers_templates_id = $importersTemplates->id;
                    $childModel->name = $child['name'];
                    $childModel->mapping_field = key_exists("mapping_field", $child) ? $child['mapping_field'] : $child['name'];
                    $childModel->parent()->associate($root);
                    $childModel->save();
                }
            }
        }
    }
}
