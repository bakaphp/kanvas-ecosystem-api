<?php

declare(strict_types=1);

namespace Kanvas\Imports\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\Models\ImportSource;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class ImportSourceData extends Data
{
    /**
     * @param list<array{pattern: string, filter?: array{column: string, in: list<string>}|null, required?: bool}> $files
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly Regions $region,
        public readonly FilesystemMapper $mapper,
        public readonly ImportConnection $connection,
        public readonly string $name,
        public readonly array $files,
        public readonly ?Warehouses $warehouse = null,
        public readonly ?Channels $channel = null,
        public readonly ?string $root = null,
        public readonly bool $unpublishMissing = false,
        public readonly ?array $extra = null,
        public readonly ?string $schedule = null,
        public readonly ?string $timezone = null,
        public readonly bool $isActive = true,
    ) {
    }

    /**
     * Resolves every id in the input inside the branch's company. The run-as user defaults to the
     * company owner, and a chosen user must belong to the company.
     */
    public static function fromMultiple(
        AppInterface $app,
        CompaniesBranches $branch,
        array $input,
        ?FilesystemMapper $mapper = null
    ): self {
        $company = $branch->company;

        if ($mapper === null && empty($input['filesystem_mapper_id'])) {
            throw new ValidationException('filesystem_mapper_id is required.');
        }

        if (empty($input['import_connection_id'])) {
            throw new ValidationException('import_connection_id is required.');
        }

        return new self(
            app: $app,
            branch: $branch,
            user: self::runAsUser($branch, $input['users_id'] ?? null),
            region: isset($input['regions_id'])
                ? RegionRepository::getByIdOrGlobal((int) $input['regions_id'], $company, $app)
                : RegionRepository::getDefault($company),
            mapper: $mapper ?? FilesystemMapper::getByIdFromCompanyApp((int) $input['filesystem_mapper_id'], $company, $app),
            connection: ImportConnection::getUsableById((int) ($input['import_connection_id'] ?? 0), $app, $company),
            name: trim((string) ($input['name'] ?? '')),
            files: (array) ($input['files'] ?? []),
            warehouse: isset($input['warehouses_id'])
                ? WarehouseRepository::getById((int) $input['warehouses_id'], $company, $app)
                : null,
            channel: isset($input['channels_id'])
                ? ChannelRepository::getByIdOrGlobal((int) $input['channels_id'], $company, $app)
                : null,
            root: $input['root'] ?? null,
            unpublishMissing: (bool) ($input['unpublish_missing'] ?? false),
            extra: $input['extra'] ?? null,
            schedule: $input['schedule'] ?? null,
            timezone: $input['timezone'] ?? null,
            isActive: (bool) ($input['is_active'] ?? true),
        );
    }

    /**
     * Partial update: every key missing from the input keeps the source's current value.
     */
    public static function forUpdate(ImportSource $source, array $input): self
    {
        return self::fromMultiple(
            $source->app,
            $source->branch,
            array_merge(
                [
                    'users_id' => $source->users_id,
                    'regions_id' => $source->regions_id,
                    'filesystem_mapper_id' => $source->filesystem_mapper_id,
                    'import_connection_id' => $source->import_connections_id,
                    'warehouses_id' => $source->warehouses_id,
                    'channels_id' => $source->channels_id,
                    'name' => $source->name,
                    'files' => $source->files,
                    'root' => $source->root,
                    'unpublish_missing' => $source->unpublish_missing,
                    'extra' => $source->extra,
                    'schedule' => $source->schedule,
                    'timezone' => $source->timezone,
                    'is_active' => $source->is_active,
                ],
                $input
            )
        );
    }

    private static function runAsUser(CompaniesBranches $branch, mixed $usersId): Users
    {
        if (empty($usersId)) {
            return $branch->company->user;
        }

        $user = Users::getById((int) $usersId);
        CompaniesRepository::userAssociatedToCompany($branch->company, $user);

        return $user;
    }
}
