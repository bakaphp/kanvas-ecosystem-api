<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\FilesystemMapper as ModelsFilesystemMapper;
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
}
