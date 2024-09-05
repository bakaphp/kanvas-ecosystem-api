<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Illuminate\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
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
        $dataImport = [];
        foreach ($records as $record) {
            $data = self::mapper($this->filesystemImports->filesystemMapper->mapping, $record);
            $dataImport[] = $data;
        }
        $job = self::getJob($this->filesystemImports->filesystemMapper->systemModule->model_name);
        $job::dispatch(
            Str::uuid()->toString(),
            $dataImport,
            $this->filesystemImports->companiesBranches,
            $this->filesystemImports->user,
            $this->filesystemImports->regions,
            $this->filesystemImports->app
        );
    }

    public static function mapper(array $template, array $data): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            switch (true) {
                case is_array($value):
                    $result[$key] = self::mapper($value, $data);

                    break;
                case is_string($value) && Str::startsWith($value, '_'):
                    $result[$key] = Str::after($value, '_');

                    break;
                case is_string($value):
                    $result[$key] = $data[$value] ?? null;

                    break;
                default:
                    $result[$key] = $value;
            }
        }

        return $result;
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

    public static function getJob($className): string
    {
        $job = '';
        switch ($className) {
            case Products::class:
                $job = ProductImporterJob::class;

                break;
            default:
                break;
        }

        return $job;
    }
}
