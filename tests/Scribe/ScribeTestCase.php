<?php

declare(strict_types=1);

namespace Tests\Scribe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Tests\TestCase;

/**
 * Shared setup for every Scribe test class. Seeds the chart of accounts + an OPEN fiscal period
 * covering June 2026, and provides the helpers every test reaches for.
 *
 * Eliminates the ~30-line setUp() + helper duplication that piled up across PR 1-10 test files.
 *
 * Extend this instead of TestCase when the test touches Scribe data.
 */
abstract class ScribeTestCase extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    protected Apps $kanvasApp;
    protected Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        $this->ensureFiscalPeriod();
        $this->afterScribeSetUp();
    }

    /**
     * Override in child class when a test class needs to do additional setup (extra accounts,
     * extra fiscal periods, factory seeding). Default no-op.
     */
    protected function afterScribeSetUp(): void
    {
    }

    /**
     * Look up an account by its sub-type for the current tenant. Tests use this dozens of times to
     * pick the AR / AP / Cash / Travel & Meals / etc. account without hardcoding ids.
     */
    protected function accountIdBySubType(AccountSubTypeEnum $subType): int
    {
        $row = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', $subType->value)
            ->first();
        $this->assertNotNull($row, "Expected seeded account with sub_type='{$subType->value}'.");

        return (int) $row->id;
    }

    /**
     * Build a Filesystem row for tests that need an attached PDF / image. Defaults to the current
     * tenant; pass a different $appsId to exercise cross-tenant guards.
     */
    protected function createFilesystemRow(
        ?int $appsId = null,
        string $extension = 'pdf',
        string $fileType = 'pdf',
    ): Filesystem {
        $filesystem = new Filesystem();
        $filesystem->apps_id = $appsId ?? $this->kanvasApp->getId();
        $filesystem->companies_id = $this->company->getId();
        $filesystem->users_id = static::$cachedUser->getId();
        $filesystem->name = 'test-' . Carbon::now()->format('YmdHisu') . '.' . $extension;
        $filesystem->path = 'inbound/' . $filesystem->name;
        $filesystem->url = 'https://example.test/' . $filesystem->path;
        $filesystem->size = '12345';
        $filesystem->file_type = $fileType;
        $filesystem->save();

        return $filesystem;
    }

    /**
     * Seed an OPEN fiscal period covering June 2026 unless one already exists. Overridable via
     * `protected ?array $fiscalPeriod = ['start' => ..., 'end' => ...]` for tests that want a
     * different window.
     */
    private function ensureFiscalPeriod(): void
    {
        $start = $this->fiscalPeriodStart();
        $end = $this->fiscalPeriodEnd();

        $exists = FiscalPeriod::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->exists();

        if ($exists) {
            return;
        }

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => $start,
            'period_end' => $end,
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    /**
     * Override to use a different fiscal period start. Format Y-m-d.
     */
    protected function fiscalPeriodStart(): string
    {
        return '2026-06-01';
    }

    protected function fiscalPeriodEnd(): string
    {
        return '2026-06-30';
    }
}
