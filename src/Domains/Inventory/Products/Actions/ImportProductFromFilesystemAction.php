<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Validations\Date;
use Illuminate\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use League\Csv\Reader;

class ImportProductFromFilesystemAction
{
    public function __construct(
        public FilesystemImports $filesystemImports
    ) {
    }

    public function execute()
    {
        $path = $this->getFilePath($this->filesystemImports->filesystem);

        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $headers = array_map('trim', $reader->getHeader());
        $records = $reader->getRecords($headers);

        $listOfVariants = [];
        $listOfProducts = [];
        $modelName = $this->filesystemImports->filesystemMapper->systemModule->model_name;

        foreach ($records as $record) {
            $record['extra'] = $this->filesystemImports->extra;
            $variant = $this->mapper(
                $this->filesystemImports->filesystemMapper->mapping,
                $record
            );

            $listOfVariants[$variant['handler']][] = $variant;
        }

        if ($modelName == Products::class) {
            foreach ($listOfVariants as $key => $variants) {
                $productAttributes = [];
                foreach ($variants as $variant) {
                    if (isset($variant['attributes']) && is_array($variant['attributes'])) {
                        foreach ($variant['attributes'] as $attrName => $attrValue) {
                            if (isset($attrValue['fromProduct']) && $attrValue['fromProduct'] === true) {
                                $productAttributes[$attrName] = $attrValue;
                            }
                        }
                    }
                }

                $productTypeId = $this->filesystemImports->filesystemMapper->configuration['product_type_id'];
                $productType = $productTypeId ? ProductsTypesRepository::getById($productTypeId, $this->filesystemImports->company, $this->filesystemImports->app) : null;
                $listOfProducts[] = [
                    'name' => $variants[0]['product_name'],
                    'description' => $variants[0]['product_description'] ?? '',
                    'slug' => $variants[0]['productSlug'] ?? Str::slug($variants[0]['product_name']),
                    'sku' => $variants[0]['sku'],
                    'status' => $variants[0]['status'],
                    'customFields' => [],
                    'variants' => $variants,
                    'attributes' => $productAttributes,
                    'price' => 0.0,
                    'productType' => $productType ? [
                        'id' => $productType->id,
                        'name' => $productType->name,
                        'weight' => $productType->weight
                    ] : null
                ];
            }
        }

        ProductImporterJob::class::dispatch(
            $this->filesystemImports->uuid,
            $listOfProducts,
            $this->filesystemImports->companiesBranches,
            $this->filesystemImports->user,
            $this->filesystemImports->regions,
            $this->filesystemImports->app,
            $this->filesystemImports
        );
    }

    public function mapper(array $template, array $data): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            $targetKey = ($key === 'variant_name') ? 'name' : $key;

            if ($key === 'attributes' && is_array($value)) {
                $result[$targetKey] = $this->mapper($value, $data);
                continue;
            }

            if (is_array($value)) {
                $result[$targetKey] = $this->mapper($value, $data);
                continue;
            }

            $result[$targetKey] = match (true) {
                is_string($value) && str_starts_with($value, '_') => substr($value, 1),
                is_string($value) && str_starts_with($value, 'date_') => Date::createFromFormat($data[substr($value, 5)] ?? ''),
                is_string($value) => $data[$value] ?? null,
                default => $value,
            };

            if ($targetKey === 'files' && is_string($result[$targetKey]) && $result[$targetKey] !== '') {
                $result[$targetKey] = Date::explodeFileStringBasedOnDelimiter($result[$targetKey]);
            } elseif (is_string($result[$targetKey]) && Date::isValidDate($result[$targetKey])) {
                $result[$targetKey] = Date::createFromFormat($result[$targetKey]);
            }
        }

        return $result;
    }

    private function getFilePath(Filesystem $filesystem): string
    {
        $service = (new FilesystemServices($this->filesystemImports->app));
        return $service->getFileLocalPath($filesystem);
    }
}
