<?php
declare(strict_types=1);

namespace Kanvas\ImportersRequests\Actions;

use Kanvas\ImportersRequests\Models\ImporterRequest;
use Kanvas\MappersImportersTemplates\Models\MapperImporterTemplate;
use Kanvas\Filesystem\Models\Filesystem;
use League\Csv\Reader;
use Illuminate\Support\Facades\Storage;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Apps\Models\Apps;

class ImporterFromMapperAction
{
    
    public function __construct(
        private ImporterRequest $importerRequest,
        private MapperImporterTemplate $mapperImporterTemplate
    ) {
    }

    public function execute(): void
    {
        
        $path = $this->getFilePath($this->importerRequest->filesystem);
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $records = $reader->getRecords();
        $data=[];
        foreach ($records as $record) {
            $data[] = $this->mapper($this->mapperImporterTemplate->mapper, $record);
        }
        $systemModuleImportDto = $this->mapperImporterTemplate->systemModules->importer_job::dispatchSync(
            $this->importerRequest->uuid,
            $data,
            $this->importerRequest->branches,
            $this->importerRequest->user,
            $this->importerRequest->region,
            app(Apps::class)
        );

    }

    private function mapper(array $template, array $data)
    {
        $result = [];
        foreach($template as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->mapper($value, $data);
            } elseif (is_string($value)) {
                if(strpos($value, "_") === 0) {
                    $value = ltrim($value, "_");
                    $result[$key] = $value;
                } else {
                    $result[$key] = $data[$value];
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function getFilePath(Filesystem $filesystem): string
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
