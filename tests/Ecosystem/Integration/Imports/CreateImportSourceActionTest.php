<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\Actions\CreateImportConnectionAction;
use Kanvas\Imports\DataTransferObject\ImportConnectionData;
use Kanvas\Imports\Enums\ImportDriverEnum;
use Tests\Ecosystem\Integration\Imports\Concerns\CreatesImportSources;
use Tests\TestCase;

final class CreateImportSourceActionTest extends TestCase
{
    use CreatesImportSources;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    public function testNormalizesFilesAndDefaultsThemToRequired(): void
    {
        $source = $this->makeSource([
            ['pattern' => ' MP10425.csv '],
            ['pattern' => 'MP22154.csv', 'filter' => ['column' => 'New/Used', 'in' => ['Used']], 'required' => false],
        ]);

        $this->assertSame(
            [
                ['pattern' => 'MP10425.csv', 'filter' => null, 'required' => true],
                ['pattern' => 'MP22154.csv', 'filter' => ['column' => 'New/Used', 'in' => ['Used']], 'required' => false],
            ],
            $source->files
        );
        $this->assertSame('0 1 * * *', $source->effectiveSchedule());
        $this->assertSame('America/New_York', $source->effectiveTimezone());
    }

    public function testRejectsAConnectionOwnedByAnotherCompany(): void
    {
        $foreign = $this->makeConnection(['companies_id' => $this->importUser()->getCurrentCompany()->getId() + 100000]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Connection does not belong to this company.');

        $this->makeSource([['pattern' => 'MP10425.csv']], connection: $foreign);
    }

    public function testRejectsAFilterWithoutValues(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeSource([['pattern' => 'MP10425.csv', 'filter' => ['column' => 'New/Used', 'in' => []]]]);
    }

    public function testRejectsASourceWithNoFiles(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeSource([]);
    }

    public function testConnectionPasswordIsEncryptedAtRestAndHiddenFromArrays(): void
    {
        $connection = $this->makeConnection(['password' => 'plain-text-secret']);

        $stored = DB::connection('ecosystem')->table('import_connections')->where('id', $connection->getId())->value('password');

        $this->assertNotSame('plain-text-secret', $stored);
        $this->assertSame('plain-text-secret', $connection->fresh()->password);
        $this->assertArrayNotHasKey('password', $connection->toArray());
    }

    public function testCreatingAConnectionRejectsAPrivateHost(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-public address');

        $this->createConnection(host: '169.254.169.254');
    }

    public function testCreatingAConnectionRejectsAnInvalidTimezone(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid timezone');

        $this->createConnection(host: '8.8.8.8', timezone: 'EST5EDT-ish');
    }

    private function createConnection(string $host, ?string $timezone = null): void
    {
        new CreateImportConnectionAction(
            new ImportConnectionData(
                app: app(Apps::class),
                company: null,
                user: $this->importUser(),
                name: 'Feeds',
                driver: ImportDriverEnum::SFTP,
                host: $host,
                username: 'feeds',
                password: 'secret',
                timezone: $timezone,
            )
        )->execute();
    }
}
