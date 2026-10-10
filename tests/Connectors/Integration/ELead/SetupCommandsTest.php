<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use App\Console\Commands\Connectors\Elead\SyncUsersCommand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\Employee;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the skip test deletes the eLeads subscription id on the shared test company, a Redis-backed
 * setting the other eLeads tests set and read in parallel.
 */
#[Group('serial')]
final class SetupCommandsTest extends TestCase
{
    use DatabaseTransactions;

    public static function commands(): array
    {
        return [
            'company entities' => ['kanvas:elead-sync-company-entities', []],
            'users' => ['kanvas:elead-sync-users', ['--create-missing' => '0']],
        ];
    }

    #[DataProvider('commands')]
    public function testSkipsCompanyWithoutEleadConfiguration(string $command, array $options): void
    {
        $app = app(Apps::class);
        $company = Auth::user()->getCurrentCompany();
        $company->del(CustomFieldEnum::COMPANY->value);

        $this->artisan($command, [
            'app_id' => $app->getId(),
            'company_ids' => (string) $company->getId(),
            ...$options,
        ])
            ->expectsOutputToContain('does not have eLeads configuration')
            ->assertSuccessful();
    }

    public static function generatedEmails(): array
    {
        return [
            'bare website' => ['acme.com', 'johnoneilacmemotors@acme.com'],
            'full url with www' => ['https://www.Acme.com/contact', 'johnoneilacmemotors@acme.com'],
            'no website' => ['', 'johnoneilacmemotors@acmemotors.io'],
            'website without a host' => ['n/a', 'johnoneilacmemotors@acmemotors.io'],
        ];
    }

    #[DataProvider('generatedEmails')]
    public function testGeneratesEmailForEmployeeWithoutOne(string $website, string $expected): void
    {
        $company = new Companies();
        $company->name = 'Acme Motors';
        $company->website = $website;

        $employee = new Employee();
        $employee->firstName = 'John';
        $employee->lastName = "O'Neil";

        $command = new class () extends SyncUsersCommand {
            public function generate(Companies $company, Employee $employee): ?string
            {
                return $this->generateEmployeeEmail($company, $employee);
            }
        };

        $this->assertSame($expected, $command->generate($company, $employee));

        $employee->firstName = null;
        $employee->lastName = null;
        $this->assertNull($command->generate($company, $employee));
    }

    public function testSyncUsersRefusesToCreateUsersWithoutAPassword(): void
    {
        $this->artisan('kanvas:elead-sync-users', [
            'app_id' => app(Apps::class)->getId(),
            'company_ids' => (string) Auth::user()->getCurrentCompany()->getId(),
        ])
            ->expectsOutputToContain('--password is required')
            ->assertFailed();
    }
}
