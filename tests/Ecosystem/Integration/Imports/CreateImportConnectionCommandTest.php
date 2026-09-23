<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Imports\Enums\ImportDriverEnum;
use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CreateImportConnectionCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testCreatesAnAppWideConnection(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $name = 'Dealer feeds ' . uniqid();

        $this->artisan('kanvas:imports:create-connection', [
            'app_id' => app(Apps::class)->getId(),
            '--user-id' => $user->getId(),
            '--name' => $name,
            '--driver' => 'ftp',
            '--host' => '8.8.8.8',
            '--username' => 'feeds',
            '--password' => 'secret',
            '--schedule' => '0 1 * * *',
            '--timezone' => 'America/New_York',
        ])
            ->expectsOutputToContain('(app-wide)')
            ->assertSuccessful();

        $connection = ImportConnection::query()->where('name', $name)->firstOrFail();
        $this->assertTrue($connection->isAppWide());
        $this->assertSame(ImportDriverEnum::FTP, $connection->driver);
        $this->assertSame(21, $connection->port);
        $this->assertSame('secret', $connection->password);
    }

    public function testAnAppWideConnectionNeedsAnOwner(): void
    {
        $this->artisan('kanvas:imports:create-connection', [
            'app_id' => app(Apps::class)->getId(),
            '--name' => 'x',
            '--host' => '8.8.8.8',
            '--username' => 'feeds',
            '--password' => 'secret',
        ])
            ->expectsOutputToContain('--user-id is required')
            ->assertFailed();
    }
}
