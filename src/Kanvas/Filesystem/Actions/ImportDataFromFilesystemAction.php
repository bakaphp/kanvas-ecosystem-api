<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Enums\StateEnums;
use DateTime;
use Exception;
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
        $headers = array_map('trim', $reader->getHeader());
        $records = $reader->getRecords($headers);

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
                $variant['price'] = filter_var($variant['price'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
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

                /**
                 * @todo move this, this is not the way, but quick test
                 */
                if (! empty($appDefaultAttributes)) {
                    // Check if we have variants and if the first variant has attributes
                    $variantAttributes = isset($variants[0]['attributes']) ? $variants[0]['attributes'] : [];

                    foreach ($appDefaultAttributes as $defaultAttribute) {
                        // Check if the attribute exists in the variant and it's not null
                        $existsInVariant = false;
                        foreach ($variantAttributes as $variantAttribute) {
                            if ($variantAttribute['name'] === $defaultAttribute['name'] && $variantAttribute['value'] !== null) {
                                $existsInVariant = true;

                                break;
                            }
                        }

                        // Only add if it doesn't exist in the variant or is null
                        if (! $existsInVariant) {
                            $variants[0]['attributes'][] = [
                                'name' => $defaultAttribute['name'],
                                'value' => $defaultAttribute['value'],
                            ];
                        }
                    }
                }

                // If we only have one variant, you can assign the attributes directly from the variant
                if (count($variants) == 1) {
                    $attributes = $variants[0]['attributes'];
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
                is_string($value) && Str::startsWith($value, 'date_') => $this->createFromFormat($data[Str::after($value, 'date_')]),
                is_string($value) => $data[$value] ?? null,
                default => $value,
            };

            if ($key == 'files' && ! empty($result[$key]) && is_string($result[$key])) {
                $result[$key] = $this->explodeFileStringBasedOnDelimiter($result[$key]);
            }

            if (is_string($result[$key]) && $this->isValidDate($result[$key])) {
                $result[$key] = $this->createFromFormat($result[$key]);
            }
        }

        return $result;
    }

    protected function isValidDate(string $dateString): bool
    {
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateString) ?:
                DateTime::createFromFormat('Y-m-d', $dateString) ?:
                DateTime::createFromFormat('m/d/Y', $dateString) ?:
                DateTime::createFromFormat('d/m/Y', $dateString) ?:
                DateTime::createFromFormat('m/d/y', $dateString) ?:
                DateTime::createFromFormat('d-m-Y', $dateString) ?:
                DateTime::createFromFormat('Y-m-d', $dateString) ?:
                DateTime::createFromFormat('j/n/Y', $dateString);

        return $date !== false;
    }

    protected function createFromFormat(string $dateString): ?string
    {
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateString) ?:
                DateTime::createFromFormat('Y-m-d', $dateString) ?:
                DateTime::createFromFormat('m/d/Y', $dateString) ?:
                DateTime::createFromFormat('d/m/Y', $dateString) ?:
                DateTime::createFromFormat('m/d/y', $dateString) ?:
                DateTime::createFromFormat('d-m-Y', $dateString) ?:
                DateTime::createFromFormat('j/n/Y', $dateString);

        if (! $date) {
            $timestamp = strtotime($dateString);
            if ($timestamp !== false) {
                return $timestamp;
            } else {
                throw new Exception('Invalid date format');
            }
        }

        return $date->format('Y-m-d H:i:s');
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
        $service = (new FilesystemServices($this->filesystemImports->app));

        return $service->getFileLocalPath($filesystem);
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
