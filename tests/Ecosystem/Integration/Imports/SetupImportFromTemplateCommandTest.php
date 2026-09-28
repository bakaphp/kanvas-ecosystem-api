<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Imports\Models\ImportSource;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Users\Models\Users;
use Tests\Ecosystem\Integration\Imports\Concerns\CreatesImportSources;
use Tests\TestCase;

final class SetupImportFromTemplateCommandTest extends TestCase
{
    use CreatesImportSources;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    public function testCreatesThenReusesTheCompanyMapper(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $arguments = [
            'template' => 'dealer_vehicle_csv',
            'app_id' => $app->getId(),
            'company_id' => $company->getId(),
            '--option' => ['price_source=price_first'],
            '--user-id' => $user->getId(),
        ];

        $this->artisan('kanvas:imports:setup-from-template', $arguments)
            ->expectsOutputToContain('Created mapper')
            ->assertSuccessful();

        $this->artisan('kanvas:imports:setup-from-template', $arguments)
            ->expectsOutputToContain('Reused mapper')
            ->assertSuccessful();

        $template = ImportTemplateEnum::DEALER_VEHICLE_CSV->template();
        $mappers = FilesystemMapper::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('name', $template->mapperName($template->resolveOptions(['price_source' => 'price_first'])))
            ->get();
        $this->assertCount(1, $mappers);
    }

    public function testWithAConnectionItAlsoCreatesTheScheduledImport(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $this->setupInventory($app, $company, $user);
        $connection = $this->makeConnection();
        $channel = Channels::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->artisan('kanvas:imports:setup-from-template', [
            'template' => 'dealer_vehicle_csv',
            'app_id' => $app->getId(),
            'company_id' => $company->getId(),
            '--user-id' => $user->getId(),
            '--connection' => $connection->getId(),
            '--file' => ['MP10425.csv', 'MP22154.csv|Used only|optional'],
            '--channel' => $channel->getId(),
            '--name' => 'Rooftop test nightly',
        ])
            ->expectsOutputToContain('Created scheduled import')
            ->assertSuccessful();

        $source = ImportSource::query()->where('companies_id', $company->getId())->where('name', 'Rooftop test nightly')->firstOrFail();
        $this->assertTrue($source->unpublish_missing);
        $this->assertSame($channel->getId(), (int) $source->channels_id);
        $this->assertEquals(['column' => 'New/Used', 'in' => ['Used', 'U']], $source->files[1]['filter']);
        $this->assertFalse($source->files[1]['required']);
    }

    public function testRerunningTheSetupReusesTheScheduledImportInsteadOfDuplicatingIt(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $this->setupInventory($app, $company, $user);
        $connection = $this->makeConnection();
        $arguments = [
            'template' => 'dealer_vehicle_csv',
            'app_id' => $app->getId(),
            'company_id' => $company->getId(),
            '--user-id' => $user->getId(),
            '--connection' => $connection->getId(),
            '--file' => ['MP10425.csv'],
            '--channel' => Channels::fromApp($app)->fromCompany($company)->firstOrFail()->getId(),
            '--name' => 'Rerun me',
        ];

        $this->artisan('kanvas:imports:setup-from-template', $arguments)
            ->expectsOutputToContain('Created scheduled import')
            ->assertSuccessful();

        $this->artisan('kanvas:imports:setup-from-template', $arguments)
            ->expectsOutputToContain('Reused scheduled import')
            ->assertSuccessful();

        $this->assertCount(1, ImportSource::query()->where('companies_id', $company->getId())->get());

        // A different file list is a different feed, so that one still gets its own import.
        $this->artisan('kanvas:imports:setup-from-template', array_merge($arguments, [
            '--file' => ['MP24456.csv'],
            '--name' => 'Second feed',
        ]))
            ->expectsOutputToContain('Created scheduled import')
            ->assertSuccessful();

        $this->assertCount(2, ImportSource::query()->where('companies_id', $company->getId())->get());
    }

    public function testFailsOnAnUnknownTemplate(): void
    {
        /** @var Users $user */
        $user = auth()->user();

        $this->artisan('kanvas:imports:setup-from-template', [
            'template' => 'shopify_csv',
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $user->getCurrentCompany()->getId(),
        ])
            ->expectsOutputToContain('Unknown template')
            ->assertFailed();
    }
}
