<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Exception;
use Illuminate\Auth\AuthenticationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\CreateFileSystemImportAction;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\Actions\UpdateFilesystemMapperAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemImport;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapperUpdate;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper as ModelsFilesystemMapper;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\SystemModules\Models\SystemModules;
use League\Csv\Reader;

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

    public function update(mixed $root, array $req): ModelsFilesystemMapper
    {
        $req = $req['input'];
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $mapper = ModelsFilesystemMapper::getByIdFromCompanyApp($req['mapper_id'], $user->getCurrentCompany(), $app);
        $mapperDto = FilesystemMapperUpdate::viaRequest(
            $app,
            $branch,
            $user,
            $req
        );

        return (new UpdateFilesystemMapperAction($mapper, $mapperDto))->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        if (! $user->isAdmin()) {
            throw new AuthenticationException('You are not allowed to perform this action');
        }
        $filesystemMapper = FilesystemImports::getByIdFromCompanyApp($req['id'], $company, $app);

        if ($filesystemMapper->imports->count()) {
            throw new Exception('You cannot delete this mapper because it has imports');
        }

        return $filesystemMapper->delete();
    }

    public function process(mixed $root, array $req): FilesystemImports
    {
        $input = $req['input'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        //$filesystem = Filesystem::getByIdFromCompanyApp($input['filesystem_id'], $company, $app);
        $filesystem = Filesystem::getById($input['filesystem_id'], $app);
        $mapper = ModelsFilesystemMapper::getByIdFromCompanyApp($input['filesystem_mapper_id'], $company, $app);
        $regions = Regions::getByIdFromCompanyApp($input['regions_id'], $company, $app);
        $dto = FilesystemImport::from([
            'app' => $app,
            'users' => $user,
            'companies' => $company,
            'regions' => $regions,
            'companiesBranches' => $user->getCurrentBranch(),
            'filesystem' => $filesystem,
            'filesystemMapper' => $mapper,
            'extra' => $input['extra'] ?? null,
        ]);

        $fileSystemService = new FilesystemServices($app, $company);
        $path = $fileSystemService->getFilePath($filesystem);

        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $records = $reader->getHeader();

        if ($app->has(AppSettingsEnums::FILESYSTEM_MAPPER_HEADER_VALIDATION->getValue())) {
            $this->validateFields($mapper, $records);
        }

        $import = (new CreateFilesystemImportAction($dto))->execute();

        return $import;
    }

    public function validateFields(ModelsFilesystemMapper $fileMapper, $fileHeader)
    {
        $mappingHeader = array_map('strtolower', array_map('trim', $fileMapper->file_header));
        $fileHeaderFields = array_map('strtolower', array_map('trim', $fileHeader));

        $invalidFile = array_filter($fileHeaderFields, function ($field) use ($mappingHeader) {
            return ! in_array($field, $mappingHeader);
        });

        if (! empty($invalidFile)) {
            $errorMessage = sprintf(
                "Validation failed for mapping '%s'. The following fields were not found: %s",
                $fileMapper->name,
                implode(', ', $mappingHeader),
            );

            throw new ValidationException($errorMessage);
        }

        return true;
    }
}
