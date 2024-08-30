<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\ImporterTemplate;

use Kanvas\MappersImportersTemplates\Models\MapperImporterTemplate;
use Kanvas\MappersImportersTemplates\DataTransferObject\MapperImporterTemplate as MapperImportersTemplatesDto;
use Kanvas\Apps\Models\Apps;
use Kanvas\MappersImportersTemplates\Actions\CreateMapperImporterTemplateAction;

class ImporterTemplateManagementMutation
{
    public function create(mixed $root, array $req): MapperImporterTemplate
    {
        $req = $req['input'];
        $dto = new MapperImportersTemplatesDto(
            users: auth()->user(),
            companies: auth()->user()->getCurrentCompany(),
            apps: app(Apps::class),
            name: $req['name'],
            attributes: $req['attributes'],
            description: $req['description'] ?? null,
        );
        return (new CreateMapperImporterTemplateAction($dto))->execute();
    }
}
