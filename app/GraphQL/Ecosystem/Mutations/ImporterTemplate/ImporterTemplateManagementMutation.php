<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\ImporterTemplate;
use Kanvas\ImportersTemplates\Actions\CreateImportersTemplatesAction;
use Kanvas\ImportersTemplates\DataTransferObject\ImportersTemplates as ImportersTemplatesDto;
use Kanvas\ImportersTemplates\Models\ImportersTemplates;
use Kanvas\Apps\Models\Apps;

class ImporterTemplateManagementMutation
{
    public function create(mixed $root, array $req): ImportersTemplates
    {
        $req = $req['input'];
        $dto = new ImportersTemplatesDto(
            users: auth()->user(),
            companies: auth()->user()->getCurrentCompany(),
            apps: app(Apps::class),
            name: $req['name'],
            description: $req['description'] ?? null,
            attributes: $req['attributes']
        );
        return (new CreateImportersTemplatesAction($dto))->execute();
    }
}