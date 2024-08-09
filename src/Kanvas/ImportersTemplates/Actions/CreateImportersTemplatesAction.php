<?php
declare(strict_types=1);

namespace Kanvas\ImportersTemplates\Actions;

use Kanvas\ImportersTemplates\Models\ImportersTemplates;
use Kanvas\ImportersTemplates\DataTransferObject\ImportersTemplates as ImportersTemplatesDto;

class CreateImportersTemplatesAction
{
    public function __construct(protected ImportersTemplatesDto $data)
    {
    }

    public function execute(): ImportersTemplates
    {
        $importersTemplates = ImportersTemplates::create([
            'apps_id' => $this->data->apps->getId(),
            'users_id' => $this->data->users->getId(),
            'companies_id' => $this->data->companies->getId(),
            'name' => $this->data->name,
            'description' => $this->data->description
        ]);
        $this->createAttributes($importersTemplates, $this->data->attributes);
        return $importersTemplates;
    }

    protected function createAttributes(ImportersTemplates $importersTemplates, array $attributes, int $parentId = 0) : void
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
