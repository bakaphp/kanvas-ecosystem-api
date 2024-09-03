<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\CreateFileSystemImportAction;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemImport;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper as ModelsFilesystemMapper;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\SystemModules\Models\SystemModules;

class FilesystemMapperMutation
{
    public function create(mixed $root, array $req): ModelsFilesystemMapper
    {
        $req = $req['input'];
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $systemModule = SystemModules::getById($req['system_module_id'], $app);

        $mapperDto = FilesystemMapper::viaRequest(
            $app,
            $branch,
            $user,
            $systemModule,
            $req
        );

        return (new CreateFilesystemMapperAction($mapperDto))->execute();
    }

    public function process(mixed $root, array $req): FilesystemImports
    {
        $input = $req['input'];
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $filesystem = Filesystem::getByIdFromCompanyApp($input['filesystem_id'], $company, $app);
        $mapper = ModelsFilesystemMapper::getByIdFromCompanyApp($input['filesystem_mapper_id'], $company, $app);
        $regions = Regions::getByIdFromCompanyApp($input['regions_id'], $company, $app);
        $dto = FilesystemImport::from([
            'app' => app(Apps::class),
            'users' => auth()->user(),
            'companies' => auth()->user()->getCurrentCompany(),
            'regions' => $regions,
            'companiesBranches' => auth()->user()->getCurrentBranch(),
            'filesystem' => $filesystem,
            'filesystemMapper' => $mapper,
        ]);

        $import = (new CreateFilesystemImportAction($dto))->execute();

        return $import;
    }
}
