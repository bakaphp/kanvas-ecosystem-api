<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Validations\Date;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use League\Csv\Reader;
use RuntimeException;
use Throwable;

class ImportProductFromFilesystemAction
{
    public function __construct(
        public FilesystemImports $filesystemImports
    ) {
    }

    public function execute(): void
    {
        // Stream the CSV → apply the mapper row-by-row → emit each completed
        // product (with its variants embedded) as a single JSONL line. The
        // worker (ProductImporterJob) then streams the JSONL via
        // AbstractImporterJob::iterateImporterRows() — neither this action
        // nor the worker materializes the full payload in memory.
        //
        // Assumption: the CSV is sorted by handler so all variants of a
        // product are contiguous. We detect violations and fail with a clear
        // error rather than silently producing duplicate products.
        $jsonlPath = $this->streamCsvToJsonl();

        try {
            $jsonlFilesystem = $this->uploadJsonl($jsonlPath);
        } finally {
            if (file_exists($jsonlPath)) {
                @unlink($jsonlPath);
            }
        }

        $extra = is_array($this->filesystemImports->extra) ? $this->filesystemImports->extra : [];
        $extra['streamable_filesystem_id'] = $jsonlFilesystem->getId();
        $this->filesystemImports->extra = $extra;
        $this->filesystemImports->saveOrFail();

        ProductImporterJob::dispatch(
            $this->filesystemImports->uuid,
            [],
            $this->filesystemImports->companiesBranches,
            $this->filesystemImports->user,
            $this->filesystemImports->regions,
            $this->filesystemImports->app,
            $this->filesystemImports,
        );
    }

    private function streamCsvToJsonl(): string
    {
        $csvPath = $this->getFilePath($this->filesystemImports->filesystem);
        $jsonlPath = sys_get_temp_dir() . '/transformed-' . $this->filesystemImports->uuid . '.jsonl';

        $this->streamCsvFileToJsonlFile($csvPath, $jsonlPath);

        return $jsonlPath;
    }

    /**
     * The pure transformation: read a local CSV, write a local JSONL with one
     * fully-formed product per line. Public so unit tests can exercise the
     * variant-grouping and sorted-assumption logic without needing S3 / DB
     * round-trips. The action's execute() wraps this with the I/O
     * orchestration (S3 download/upload, FilesystemImports update, dispatch).
     */
    public function streamCsvFileToJsonlFile(string $csvPath, string $jsonlPath): void
    {
        $reader = Reader::from($csvPath);
        $reader->setHeaderOffset(0);
        $headers = array_map('trim', $reader->getHeader());

        $out = @fopen($jsonlPath, 'w');
        if ($out === false) {
            throw new RuntimeException('Could not open temp JSONL file for transformed import: ' . $jsonlPath);
        }

        $modelName = $this->filesystemImports->filesystemMapper->systemModule->model_name;
        $mapping = $this->filesystemImports->filesystemMapper->mapping;
        /** @var array<string, mixed> $configuration */
        $configuration = is_array($this->filesystemImports->filesystemMapper->configuration)
            ? $this->filesystemImports->filesystemMapper->configuration
            : [];
        $channelsId = $configuration['channels_id'] ?? null;
        $productType = $this->resolveProductType($configuration);

        try {
            $emittedHandlers = [];
            $currentHandler = null;
            $currentVariants = [];

            foreach ($reader->getRecords($headers) as $record) {
                $record['extra'] = $this->filesystemImports->extra;
                $variant = $this->mapper($mapping, $record);

                if ($channelsId !== null) {
                    $variant['channels'] = [
                        [
                            'channels_id' => $channelsId,
                            'price' => $this->cleanPrice($variant['price'] ?? 0.0),
                            'discounted_price' => $this->cleanPrice($variant['discounted_price'] ?? 0.0),
                        ],
                    ];
                }

                $handler = (string) ($variant['handler'] ?? '');

                if ($handler !== $currentHandler) {
                    if ($currentHandler !== null) {
                        $this->emitProductIfNeeded($out, $modelName, $currentVariants, $productType);
                        $emittedHandlers[$currentHandler] = true;
                    }

                    if (isset($emittedHandlers[$handler])) {
                        throw new RuntimeException(sprintf(
                            "CSV rows must be grouped by handler for streaming import. Found out-of-order handler '%s' after it was already emitted. Please sort the CSV by the handler column before uploading.",
                            $handler,
                        ));
                    }

                    $currentHandler = $handler;
                    $currentVariants = [];
                }

                $currentVariants[] = $variant;
            }

            if ($currentHandler !== null) {
                $this->emitProductIfNeeded($out, $modelName, $currentVariants, $productType);
            }
        } catch (Throwable $e) {
            fclose($out);
            if (file_exists($jsonlPath)) {
                @unlink($jsonlPath);
            }

            throw $e;
        }

        fclose($out);
    }

    /**
     * @param resource $out
     * @param array<int, array<array-key, mixed>> $variants
     */
    private function emitProductIfNeeded($out, string $modelName, array $variants, ?ProductsTypes $productType): void
    {
        if ($modelName !== Products::class || $variants === []) {
            return;
        }

        $product = $this->buildProductFromVariants($variants, $productType);
        fwrite($out, json_encode($product, JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @param array<int, array<array-key, mixed>> $variants
     * @return array<string, mixed>
     */
    private function buildProductFromVariants(array $variants, ?ProductsTypes $productType): array
    {
        $productAttributes = [];
        foreach ($variants as $variant) {
            if (! isset($variant['attributes']) || ! is_array($variant['attributes'])) {
                continue;
            }

            foreach ($variant['attributes'] as $attrName => $attrValue) {
                if (isset($attrValue['fromProduct']) && $attrValue['fromProduct'] === true) {
                    $productAttributes[$attrName] = $attrValue;
                }
            }
        }

        return [
            'name' => $variants[0]['product_name'],
            'description' => $variants[0]['product_description'] ?? '',
            'slug' => $variants[0]['product_slug'] ?? Str::slug((string) $variants[0]['product_name']),
            'sku' => $variants[0]['sku'],
            'status' => $variants[0]['status'] ?? null,
            'customFields' => [],
            'categories' => $variants[0]['categories'] ?? [],
            'variants' => $variants,
            'attributes' => $productAttributes,
            'price' => 0.0,
            'productType' => $productType ? [
                'id' => $productType->id,
                'name' => $productType->name,
                'weight' => $productType->weight,
            ] : null,
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function resolveProductType(array $configuration): ?ProductsTypes
    {
        $productTypeId = $configuration['product_type_id'] ?? null;
        if ($productTypeId === null || $productTypeId === '' || $productTypeId === 0) {
            return null;
        }

        /** @var ProductsTypes $productType */
        $productType = ProductsTypesRepository::getById(
            (int) $productTypeId,
            $this->filesystemImports->company,
            $this->filesystemImports->app,
        );

        return $productType;
    }

    private function cleanPrice(mixed $price): float
    {
        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            return (float) preg_replace('/[\$,\s]/', '', $price);
        }

        return 0.0;
    }

    private function uploadJsonl(string $localPath): Filesystem
    {
        $service = new FilesystemServices(
            $this->filesystemImports->app,
            $this->filesystemImports->company,
        );

        $uploadedFile = new UploadedFile(
            $localPath,
            'transformed-' . $this->filesystemImports->uuid . '.jsonl',
            'application/x-ndjson',
            null,
            true,
        );

        return $service->upload($uploadedFile, $this->filesystemImports->user);
    }

    public function mapper(array $template, array $data): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            $targetKey = ($key === 'variant_name') ? 'name' : $key;

            if ($key === 'attributes' && is_array($value)) {
                $result[$targetKey] = $this->mapAttributes($value, $data);

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

            if ($targetKey === 'categories' && is_string($result[$targetKey]) && $result[$targetKey] !== '') {
                $result[$targetKey] = $this->mapCategories($result[$targetKey]);
            } elseif ($targetKey === 'files' && is_string($result[$targetKey]) && $result[$targetKey] !== '') {
                $result[$targetKey] = Date::explodeFileStringBasedOnDelimiter($result[$targetKey]);
            } elseif (is_string($result[$targetKey]) && Date::isValidDate($result[$targetKey])) {
                $result[$targetKey] = Date::createFromFormat($result[$targetKey]);
            }
        }

        return $result;
    }

    private function getFilePath(Filesystem $filesystem): string
    {
        return new FilesystemServices($this->filesystemImports->app)
                ->getFileLocalPath($filesystem);
    }

    private function mapAttributes(array $attributeTemplate, array $data): array
    {
        $mappedAttributes = $this->mapper($attributeTemplate, $data);
        $result = [];

        foreach ($mappedAttributes as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $fromProduct = $attributeData['fromProduct'] ?? false;

            foreach ($attributeData as $key => $value) {
                if ($key !== 'fromProduct') {
                    $result[] = [
                        'fromProduct' => $fromProduct,
                        'name' => $key,
                        'value' => $value,
                    ];
                }
            }
        }

        return $result;
    }

    private function mapCategories(string $categoriesString): array
    {
        $categories = array_map('trim', explode(',', $categoriesString));

        return array_map(function ($categoryName) {
            return [
                'name' => $categoryName,
                'slug' => Str::slug($categoryName),
            ];
        }, array_filter($categories));
    }
}
