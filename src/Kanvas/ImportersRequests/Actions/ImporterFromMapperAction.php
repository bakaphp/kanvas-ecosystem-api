<?php
declare(strict_types=1);

namespace Kanvas\ImportersRequests\Actions;

use Kanvas\ImportersRequests\Models\ImporterRequest;
use Kanvas\MappersImportersTemplates\Models\MapperImporterTemplate;

class ImporterFromMapperAction
{
    
    public function __construct(
        private ImporterRequest $importerRequest,
        private MapperImporterTemplate $mapperImporterTemplate
    ) {
    }

    public function execute(): void
    {
        $data = $this->mapper($this->mapperImporterTemplate->attributes()->toArray(), $this->importerRequest->data);
        $systemModuleImportDto = $this->importerRequest->systemModules->importerJob::dispatch(
            $this->importerRequest->uuid,
            $data,
            $this->importerRequest->branches(),
            $this->importerRequest->users(),
            $this->importerRequest->region(),
            app(Apps::class)
        );
    }

    private function mapper(array $template, array $data)
    {
        $result = [];

        foreach ($template as $attribute) {
            if (isset($attribute['mapping_field'])) {
                $field = $attribute['mapping_field'];
            
                if (isset($data[$field])) {
                    $result[$field] = $data[$field];
                }

                if (isset($attribute['children'])) {
                    $result[$field] = mapAttributes($attribute['children'], $data);
                }
            }
        }
        return $result;

    }
}
