<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\EnsureAgentHasDedicatedUserAction;
use Kanvas\Intelligence\Agents\Services\CompanyAssistantLocatorService;
use Kanvas\NervousSystem\Project\Actions\EnsureCompanyOperationsProjectAction;
use Throwable;

/**
 * Backfill for companies that already have a Company Brain: gives the assistant a user of its own when
 * it runs on a shared login, and opens its Operations board. Never creates an agent. Run with
 * --dry-run first: it lists every assistant that shares a login before anything is written.
 */
class EnsureCompanyOperationsProjectCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:nervous-system:ensure-company-operations-project
        {--app= : Only this app id}
        {--company= : Only this company id}
        {--dry-run : List what would change without writing}';

    protected $description = 'Give each Company Brain its own user and its Operations board.';

    private int $ensured = 0;
    private int $skipped = 0;
    private int $failed = 0;

    public function handle(): int
    {
        $companyFilter = $this->option('company') !== null ? (int) $this->option('company') : null;

        $apps = Apps::query()
            ->where('is_deleted', 0)
            ->when($this->option('app') !== null, fn (Builder $query): Builder => $query->where('id', (int) $this->option('app')))
            ->orderBy('id')
            ->get();

        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            $companyIds = DB::table('user_company_apps')
                ->select('companies_id')
                ->where('apps_id', $app->getId())
                ->distinct();

            Companies::query()
                ->whereIn('id', $companyIds)
                ->when($companyFilter !== null, fn (Builder $query): Builder => $query->where('id', $companyFilter))
                ->orderBy('id')
                ->each(fn (Companies $company) => $this->ensureFor($app, $company));
        }

        $this->info(sprintf(
            'Operations boards: %d ensured, %d skipped (no Company Brain), %d failed.',
            $this->ensured,
            $this->skipped,
            $this->failed,
        ));

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function ensureFor(Apps $app, Companies $company): void
    {
        $where = sprintf('app %d company %d', $app->getId(), $company->getId());

        try {
            $assistant = CompanyAssistantLocatorService::for($app, $company);

            if ($assistant === null) {
                $this->skipped++;

                return;
            }

            $candidates = CompanyAssistantLocatorService::candidates($app, $company);
            if ($candidates->count() > 1) {
                $this->warn(sprintf(
                    '  %s has %d Company Brain agents (%s); the assistant is %d.',
                    $where,
                    $candidates->count(),
                    $candidates->pluck('id')->implode(', '),
                    $assistant->getId(),
                ));
            }

            $ownUser = new EnsureAgentHasDedicatedUserAction($assistant);
            $shared = $ownUser->sharedBecause();
            if ($shared !== null) {
                $this->line(sprintf('  %s agent %d shares a login: %s.', $where, $assistant->getId(), $shared));
            }

            if ((bool) $this->option('dry-run')) {
                $this->line(sprintf('  would ensure: %s', $where));

                return;
            }

            $ownUser->execute();
            $project = new EnsureCompanyOperationsProjectAction($app, $company)->execute();
            $this->ensured++;
            $this->line(sprintf('  %s → project %d', $where, $project->getId()));
        } catch (Throwable $e) {
            $this->failed++;
            $this->error(sprintf('  %s FAILED: %s', $where, $e->getMessage()));
        }
    }
}
