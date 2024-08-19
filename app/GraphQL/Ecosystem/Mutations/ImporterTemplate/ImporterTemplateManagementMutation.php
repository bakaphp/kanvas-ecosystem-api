<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\ImporterTemplate;
use Kanvas\ImportersTemplates\Actions\CreateImportersTemplatesAction;
use Kanvas\ImportersTemplates\DataTransferObject\ImportersTemplates as ImportersTemplatesDto;
use Kanvas\ImportersTemplates\Models\ImportersTemplates;
use Kanvas\Apps\Models\Apps;
use Kanvas\MappersImportersTemplates\DataTransferObject\MapperImportersTemplates as MapperImportersTemplatesDto;
use Kanvas\MappersImportersTemplates\Models\MapperImportersTemplates;
use Kanvas\MappersImportersTemplates\Actions\CreateMapperImportersTemplatesAction;
class ImporterTemplateManagementMutation
{
    public function create(mixed $root, array $req): MapperImportersTemplates
    {
        $req = $req['input'];
        $dto = new MapperImportersTemplatesDto(
            users: auth()->user(),
            companies: auth()->user()->getCurrentCompany(),
            apps: app(Apps::class),
            name: $req['name'],
            description: $req['description'] ?? null,
            attributes: $req['attributes']
        );
        return (new CreateMapperImportersTemplatesAction($dto))->execute();
    }
}