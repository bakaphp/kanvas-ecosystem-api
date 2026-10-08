<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

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
