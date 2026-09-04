<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemMapperWalker;
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
    protected FilesystemMapperWalker $walker;

    public function __construct(
        public FilesystemImports $filesystemImports,
        protected ?FilesystemServices $filesystemService = null,
    ) {
        $this->walker = new FilesystemMapperWalker();
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
     *
     * `$productType` is normally resolved from `configuration.product_type_id`
     * inside this method, but unit tests can pass a stub instance to bypass
     * the DB lookup that the resolver would do.
     */
    public function streamCsvFileToJsonlFile(
        string $csvPath,
        string $jsonlPath,
        ?ProductsTypes $productType = null
    ): void {
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
        $productType ??= $this->resolveProductType($configuration);

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
                            'CSV rows must be grouped by handler for streaming import. '
                            . "Found out-of-order handler '%s' after it was already emitted. "
                            . 'Please sort the CSV by the handler column before uploading.',
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
    private function emitProductIfNeeded($out, string $modelName, array $variants, ProductsTypes $productType): void
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
    private function buildProductFromVariants(array $variants, ProductsTypes $productType): array
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
            'productType' => [
                'id' => $productType->id,
                'name' => $productType->name,
                'weight' => $productType->weight,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function resolveProductType(array $configuration): ProductsTypes
    {
        $productTypeId = $configuration['product_type_id'] ?? null;
        if ($productTypeId === null || $productTypeId === '' || $productTypeId === 0) {
            // Product types own the attribute schema for products imported under
            // them. Without one, attributes have no type to attach to and the
            // imported products would be data-orphans. Fail fast — before any
            // CSV processing — with a message that points at the fix.
            throw new ValidationException(
                'Product imports require configuration.product_type_id on the FilesystemMapper. '
                . 'Without it, imported products cannot be associated with their product type, '
                . 'breaking attribute associations. Set product_type_id on the mapper before importing.'
            );
        }

        /** @var ProductsTypes $productType */
        $productType = ProductsTypesRepository::getByIdOrGlobal(
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
        $service = $this->filesystemService ?? new FilesystemServices(
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
        return $this->walker->walk($template, $data);
    }

    private function getFilePath(Filesystem $filesystem): string
    {
        $service = $this->filesystemService ?? new FilesystemServices($this->filesystemImports->app);

        return $service->getFileLocalPath($filesystem);
    }
}
