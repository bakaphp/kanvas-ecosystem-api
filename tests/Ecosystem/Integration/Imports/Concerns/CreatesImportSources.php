<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Imports\Actions\CreateImportSourceAction;
use Kanvas\Imports\Actions\CreateMapperFromTemplateAction;
use Kanvas\Imports\DataTransferObject\ImportSourceData;
use Kanvas\Imports\DataTransferObject\MapperFromTemplate;
use Kanvas\Imports\Enums\ImportDriverEnum;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\Models\ImportSource;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Mockery;
use Tests\GraphQL\Inventory\Traits\InventoryCases;

trait CreatesImportSources
{
    use InventoryCases;

    protected const string DEALER_HEADER = 'VIN,Stock #,New/Used,Year,Make,Model,Series,MSRP,Price,Photo Url List';

    protected function importUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    protected function makeConnection(array $overrides = []): ImportConnection
    {
        return ImportConnection::create(array_merge([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => 0,
            'users_id' => $this->importUser()->getId(),
            'name' => 'Dealer feeds FTP',
            'driver' => ImportDriverEnum::FTP,
            'host' => 'ftp.dealerfeeds.test',
            'port' => 21,
            'username' => 'feeds',
            'password' => 'secret-password',
            'default_schedule' => '0 1 * * *',
            'timezone' => 'America/New_York',
        ], $overrides));
    }

    protected function dealerMapper(): FilesystemMapper
    {
        return new CreateMapperFromTemplateAction(
            new MapperFromTemplate(
                template: ImportTemplateEnum::DEALER_VEHICLE_CSV->template(),
                app: app(Apps::class),
                branch: $this->importUser()->getCurrentBranch(),
                user: $this->importUser(),
            )
        )->execute();
    }

    /**
     * @param list<array{pattern: string, filter?: array|null, required?: bool}> $files
     */
    protected function makeSource(array $files, bool $unpublishMissing = true, ?ImportConnection $connection = null): ImportSource
    {
        $app = app(Apps::class);
        $company = $this->importUser()->getCurrentCompany();
        $this->setupInventory($app, $company, $this->importUser());

        return new CreateImportSourceAction(
            new ImportSourceData(
                app: $app,
                branch: $this->importUser()->getCurrentBranch(),
                user: $this->importUser(),
                region: Regions::fromApp($app)->fromCompany($company)->firstOrFail(),
                mapper: $this->dealerMapper(),
                connection: $connection ?? $this->makeConnection(),
                name: 'Test rooftop nightly',
                files: $files,
                warehouse: Warehouses::fromApp($app)->fromCompany($company)->firstOrFail(),
                channel: Channels::fromApp($app)->fromCompany($company)->firstOrFail(),
                unpublishMissing: $unpublishMissing,
            )
        )->execute();
    }

    protected function dealerCsv(array ...$rows): string
    {
        $lines = [self::DEALER_HEADER];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines) . "\n";
    }

    protected function fakeUploads(): FilesystemServices
    {
        $user = $this->importUser();
        $filesystem = new Filesystem([
            'users_id' => $user->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'name' => 'merged.csv',
            'path' => '/test/imports/merged.csv',
            'url' => 'https://example.test/merged.csv',
            'size' => '10',
            'file_type' => 'csv',
        ]);
        $filesystem->save();

        $service = Mockery::mock(FilesystemServices::class);
        $service->shouldReceive('upload')->andReturn($filesystem);

        return $service;
    }
}
