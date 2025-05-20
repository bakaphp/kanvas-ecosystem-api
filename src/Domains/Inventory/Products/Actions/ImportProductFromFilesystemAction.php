<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Enums\StateEnums;
use DateTime;
use Exception;
use Illuminate\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Products\Models\Products;
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

                $listOfProducts[] = [
                    'name' => $variants[0]['product_name'],
                    'description' => $variants[0]['product_description'] ?? '',
                    'slug' => $variants[0]['productSlug'] ?? Str::slug($variants[0]['product_name']),
                    'sku' => $variants[0]['sku'],
                    'status' => $variants[0]['status'],
                    'customFields' => [],
                    'variants' => $variants,
                    'price' => 0.0
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
            // Determinar el valor base según el tipo y prefijos
            $result[$targetKey] = match (true) {
                is_string($value) && str_starts_with($value, '_') => substr($value, 1),
                is_string($value) && str_starts_with($value, 'date_') => $this->createFromFormat($data[substr($value, 5)] ?? ''),
                is_string($value) => $data[$value] ?? null,
                default => $value,
            };

            // Procesamiento específico para archivos y fechas
            if ($targetKey === 'files' && is_string($result[$targetKey]) && $result[$targetKey] !== '') {
                $result[$targetKey] = $this->explodeFileStringBasedOnDelimiter($result[$targetKey]);
            } elseif (is_string($result[$targetKey]) && $this->isValidDate($result[$targetKey])) {
                $result[$targetKey] = $this->createFromFormat($result[$targetKey]);
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
}
