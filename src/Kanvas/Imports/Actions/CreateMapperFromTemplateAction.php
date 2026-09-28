<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper as FilesystemMapperData;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\DataTransferObject\MapperFromTemplate;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesData;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class CreateMapperFromTemplateAction
{
    public function __construct(
        private readonly MapperFromTemplate $data,
    ) {
    }

    public function execute(): FilesystemMapper
    {
        $template = $this->data->template;
        $options = $template->resolveOptions($this->data->options);
        $signature = $template->signature($options);
        $company = $this->data->branch->company;
        $systemModule = SystemModulesRepository::getByModelName($template->systemModule, $this->data->app);

        // Matched in PHP rather than with a JSON path in SQL: legacy rows can hold non-JSON
        // `configuration`, and MySQL fails the whole query on invalid JSON text. Company is filtered
        // explicitly because fromCompany() drops the filter entirely under an app-key request.
        $existing = FilesystemMapper::query()
            ->fromApp($this->data->app)
            ->where('companies_id', $company->getId())
            ->notDeleted()
            ->where('system_modules_id', $systemModule->getId())
            ->get()
            ->first(fn (FilesystemMapper $mapper): bool => ($mapper->configuration['template']['signature'] ?? null) === $signature);

        if ($existing !== null) {
            return $existing;
        }

        $configuration = [
            'template' => [
                'key' => $template->key,
                'version' => $template->version,
                'options' => $options,
                'signature' => $signature,
            ],
        ];

        if ($template->productType !== null) {
            $configuration['product_type_id'] = new CreateProductTypeAction(
                new ProductsTypesData(
                    company: $company,
                    user: $this->data->user,
                    name: $template->productType,
                ),
                $this->data->user
            )->execute()->getId();
        }

        return new CreateFilesystemMapperAction(
            new FilesystemMapperData(
                app: $this->data->app,
                branch: $this->data->branch,
                user: $this->data->user,
                systemModule: $systemModule,
                name: $template->mapperName($options),
                header: $template->fileHeader,
                mapping: $template->mappingFor($options),
                configuration: $configuration,
                description: $template->description,
            )
        )->execute();
    }
}
