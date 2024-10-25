<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Support\Str;
use Kanvas\Event\Events\Jobs\ImporterEventJob;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Jobs\CustomerImporterJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Products\Models\Products;
use League\Csv\Reader;

class ImportDataFromFilesystemAction
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
        $records = $reader->getRecords();
        $listOfVariants = [];
        $listOfProducts = [];
        $modelName = $this->filesystemImports->filesystemMapper->systemModule->model_name;

        $appDefaultAttributes = $this->filesystemImports->app->get('default_product_import_attributes');
        foreach ($records as $record) {
            $record['extra'] = $this->filesystemImports->extra;
            $variant = $this->mapper(
                $this->filesystemImports->filesystemMapper->mapping,
                $record
            );
            if (Products::class == $modelName) {
                $variant['productSlug'] = $variant['slug'];
                $listOfVariants[$variant['productSlug']][] = $variant;
            } else {
                $listOfProducts[] = $variant;
            }
        }
        
        /**
         * @todo this structure is just for product so we need to encapsulate this in a method
         * when we are just importing product type
         */
        if ($modelName == Products::class) {
            foreach ($listOfVariants as $key => $variants) {
                if (empty($variants[0]['name']) || empty($variants[0]['slug']) || empty($variants[0]['sku'])) {
                    continue;
                }
                $attributes = [];

                if( ! empty($appDefaultAttributes)) {
                    foreach ($appDefaultAttributes as $defaultAttribute) {
                        $attributes[] = [
                            'name' => $defaultAttribute['name'],
                            'value' => $defaultAttribute['value'],
                        ];
                    }
                }
                
                //if we only have one variant we can assign the attributes to the product
                if (count($variants) == 1) {
                    $attributes = $variants[0]['attributes'];
                    //unset($variants[0]['attributes']);
                }

                $listOfProducts[] = [
                    'name' => $variants[0]['name'],
                    'description' => $variants[0]['description'],
                    'slug' => $variants[0]['productSlug'],
                    'sku' => $variants[0]['sku'],
                    'regionId' => $variants[0]['regionId'],
                    'price' => (float) ($variants[0]['price'] ?? 0),
                    'discountPrice' => (float) ($variants[0]['discountPrice'] ?? 0),
                    'quantity' => $variants[0]['quantity'] ?? 1,
                    'isPublished' => (bool) ($variants[0]['isPublished'] ?? true),
                    'files' => (array) ($variants[0]['files'] ?? []),
                    'productType' => [
                        'name' => $variants[0]['productType'] ?? StateEnums::DEFAULT_NAME->getValue(),
                        'description' => null,
                        'is_published' => true,
                        'weight' => 1,
                    ],
                    'categories' => [
                        [
                            'name' => $variants[0]['categories'],
                            'code' => strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '', $variants[0]['categories']))),
                            'is_published' => true,
                            'position' => 1,
                        ],
                    ],
                    'attributes' => $attributes,
                    'customFields' => [],
                    'variants' => $variants,
                ];
            }
        }
        $job = $this->getJob($modelName);
        $job::dispatch(
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

        /**
         * @todo
         * - assign type to attributes
         * - assign type to fields , so we can say files has to be array , x is INT and so on
         */
        foreach ($template as $key => $value) {
            $result[$key] = match (true) {
                is_array($value) => $this->mapper($value, $data),
                is_string($value) && Str::startsWith($value, '_') => Str::after($value, '_'),
                is_string($value) => $data[$value] ?? null,
                default => $value,
            };

            if ($key == 'files' && ! empty($result[$key]) && is_string($result[$key])) {
                $result[$key] = $this->explodeFileStringBasedOnDelimiter($result[$key]);
            }
        }

        return $result;
    }

    public function explodeFileStringBasedOnDelimiter(string $value): array
    {
        $delimiter = match (true) {
            Str::contains($value, '|') => '|',
            Str::contains($value, ',') => ',',
            Str::contains($value, ';') => ';',
            default => '|',
        };

        $fileLinks = explode($delimiter, $value);

        return array_map(function ($fileLink) {
            $fileLink = trim($fileLink);
            $cleanedUrl = Str::before($fileLink, '?');

            return [
                'url' => $fileLink,
                'name' => basename($cleanedUrl),
            ];
        }, $fileLinks);
    }

    private function getFilePath(Filesystem $filesystem): string
    {
        $path = $filesystem->path;
        $diskS3 = (new FilesystemServices($this->filesystemImports->app))->buildS3Storage();

        $fileContent = $diskS3->get($path);
        $filename = basename($path);
        $path = storage_path('app/csv/' . $filename);
        file_put_contents($path, $fileContent);

        return $path;
    }

    public function getJob(string $className): string
    {
        $job = '';
        switch ($className) {
            case Products::class:
                $job = ProductImporterJob::class;

                break;
            case Event::class:
                $job = ImporterEventJob::class;

                break;
            case People::class:
                $job = CustomerImporterJob::class;

                break;
            default:
                break;
        }

        return $job;
    }
}
