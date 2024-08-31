<?php

declare(strict_types=1);

namespace Kanvas\ImportersRequests\Actions;

use Baka\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\ImportersRequests\Models\ImporterRequest;
use Kanvas\MappersImportersTemplates\Models\MapperImporterTemplate;
use League\Csv\Reader;

class ImporterFromMapperAction
{
    public function __construct(
        protected ImporterRequest $importerRequest,
        protected MapperImporterTemplate $mapperImporterTemplate
    ) {
    }

    public function execute(): void
    {
        $path = $this->getFilePath($this->importerRequest->filesystem);
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $records = $reader->getRecords();
        $data = [];
        foreach ($records as $record) {
            $data[] = $this->mapper($this->mapperImporterTemplate->mapper, $record);
        }

        $this->mapperImporterTemplate->systemModules->importer_job::dispatchSync(
            $this->importerRequest->uuid,
            $data,
            $this->importerRequest->branches,
            $this->importerRequest->user,
            $this->importerRequest->region,
            $this->importerRequest->app
        );
    }

    protected function mapper(array $template, array $data): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            switch (true) {
                case is_array($value):
                    $result[$key] = $this->mapper($value, $data);

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

    protected function getFilePath(Filesystem $filesystem): string
    {
        $path = $filesystem->path;
        $filesystem = Filesystem::getById($this->importerRequest->filesystem_id);
        $diskS3 = (new FilesystemServices($this->importerRequest->app))->buildS3Storage();
        $fileContent = $diskS3->get($path);
        $filename = basename($path);
        $path = storage_path('app/public/' . $filename);
        file_put_contents($path, $fileContent);

        return $path;
    }
}
