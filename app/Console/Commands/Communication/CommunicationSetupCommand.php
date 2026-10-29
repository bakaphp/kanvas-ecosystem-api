<?php

declare(strict_types=1);

namespace App\Console\Commands\Communication;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\EnsureCommunicationSetupAction;
use Kanvas\Communication\Connections\Actions\ProbeConnectionsAction;
use Kanvas\Communication\Connections\Actions\ReleaseUnusedRegistrationsAction;
use Kanvas\Communication\Connections\Actions\ResolveIntegrationAppAction;
use Kanvas\Communication\Connections\Actions\RunConnectionCleanupAction;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Jobs\ReconcileDeliveriesJob;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Throwable;

/**
 * The durability sweep, and the operator's way to configure integrations that existed before
 * this domain. It needs no credentials, numbers or prompts: everything comes from the integration
 * rows. One integration failing never stops the others.
 */
class CommunicationSetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'communication:setup
        {--app= : The app whose messaging integrations to set up}
        {--company= : Only this company}
        {--assign-integration= : Assign an unresolved legacy integration row to --app (operator action)}
        {--all-apps : Every app with a messaging integration, as the scheduler runs it}';

    protected $description = 'Provision, repair and clean up communication connections for an app.';

    private ProviderRegistry $providers;

    public function handle(): int
    {
        $apps = $this->targetApps();

        if ($apps->isEmpty()) {
            $this->error('Pass --app=ID, or --all-apps.');

            return self::FAILURE;
        }

        // Legacy rows carry no app, so adopting them is one pass, not one per app swept.
        $this->overwriteAppService($apps->first());

        if ($this->option('assign-integration') !== null) {
            $this->assign($apps->first(), (int) $this->option('assign-integration'));
        }

        $this->resolveLegacyOwnership();

        foreach ($apps as $app) {
            $this->overwriteAppService($app);
            $this->info("app={$app->getId()} {$app->name}");

            $this->sweep($app);
        }

        return self::SUCCESS;
    }

    private function sweep(Apps $app): void
    {
        foreach ($this->integrations($app) as $integrationCompany) {
            try {
                $this->importLegacySelection($integrationCompany);

                $report = new EnsureCommunicationSetupAction($app, $integrationCompany, respectBackoff: true)->execute();

                $this->line(sprintf(
                    '  integration=%d company=%d %s%s',
                    $integrationCompany->getId(),
                    $integrationCompany->companies_id,
                    $report->outcome,
                    $report->reason !== null ? ' (' . $report->reason . ')' : ''
                ));
            } catch (Throwable $exception) {
                report($exception);
                $this->line("  integration={$integrationCompany->getId()} failed ({$exception->getMessage()})");
            }
        }

        $this->runPendingCleanups($app);

        $released = new ReleaseUnusedRegistrationsAction($app)->execute();
        $probed = new ProbeConnectionsAction($app)->execute();
        ReconcileDeliveriesJob::dispatch($app);

        $this->line("  released={$released['registrations']} receivers_off={$released['receivers']} probed={$probed}");
    }

    /**
     * Rows that predate app ownership are adopted only on evidence; the ones that cannot be are
     * listed for an operator, never guessed.
     */
    private function resolveLegacyOwnership(): void
    {
        $legacy = $this->messagingIntegrations(
            IntegrationsCompany::query()
                ->whereNull('apps_id')
                ->when($this->option('company'), fn (Builder $query, string $company) => $query->where('companies_id', (int) $company))
        );

        foreach ($legacy as $row) {
            $result = new ResolveIntegrationAppAction($row)->execute();

            if ($result['outcome'] !== ResolveIntegrationAppAction::RESOLVED) {
                $this->line(sprintf(
                    '  legacy integration=%d company=%d %s%s',
                    $row->getId(),
                    $row->companies_id,
                    $result['outcome'],
                    $result['apps'] !== [] ? ' (apps ' . implode(', ', $result['apps']) . ')' : ''
                ));
            }
        }
    }

    private function assign(Apps $app, int $integrationCompanyId): void
    {
        $row = IntegrationsCompany::query()->whereKey($integrationCompanyId)->whereNull('apps_id')->first();

        if ($row === null) {
            $this->warn("  integration={$integrationCompanyId} is not an unresolved legacy row; nothing assigned");

            return;
        }

        $row->forceFill(['apps_id' => $app->getId()])->saveOrFail();
        $this->line("  integration={$integrationCompanyId} assigned to app {$app->getId()}");
    }

    /**
     * Decision R: an integration saved before numbers were selected in the integration flow gets
     * the number its company already sends from, persisted on the row like any selection.
     */
    private function importLegacySelection(IntegrationsCompany $integrationCompany): void
    {
        $provider = $this->providers()->forIntegration($integrationCompany);

        if ($provider === null || $provider::accounts($integrationCompany) !== []) {
            return;
        }

        $selection = app($provider::provisioner())->legacySelection($integrationCompany);

        if ($selection !== []) {
            $integrationCompany->config = array_merge($integrationCompany->config ?? [], $selection);
            $integrationCompany->saveOrFail();
        }
    }

    private function runPendingCleanups(Apps $app): void
    {
        Connection::query()
            ->fromApp($app)
            ->where('setup_status', SetupStatusEnum::DISCONNECTING->value)
            ->get()
            ->each(function (Connection $connection) {
                try {
                    new RunConnectionCleanupAction($connection)->execute();
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }

    /**
     * @return Collection<int, IntegrationsCompany>
     */
    private function integrations(Apps $app): Collection
    {
        return $this->messagingIntegrations(
            IntegrationsCompany::query()
                ->fromApp($app)
                ->when($this->option('company'), fn (Builder $query, string $company) => $query->where('companies_id', (int) $company))
        );
    }

    /**
     * @return Collection<int, IntegrationsCompany>
     */
    private function messagingIntegrations(Builder $query): Collection
    {
        return $query
            ->notDeleted()
            ->with('integration')
            ->get()
            ->filter(fn (IntegrationsCompany $row): bool => $this->providers()->forIntegration($row) !== null)
            ->values();
    }

    private function providers(): ProviderRegistry
    {
        return $this->providers ??= new ProviderRegistry();
    }

    /**
     * @return Collection<int, Apps>
     */
    private function targetApps(): Collection
    {
        if ($this->option('app') !== null) {
            return Apps::query()->whereKey((int) $this->option('app'))->get();
        }

        if (! $this->option('all-apps')) {
            return collect();
        }

        $appIds = $this->messagingIntegrations(IntegrationsCompany::query()->whereNotNull('apps_id'))
            ->pluck('apps_id')
            ->merge(Connection::query()->distinct()->pluck('apps_id'))
            ->unique()
            ->all();

        return Apps::query()->whereKey($appIds)->where('is_deleted', 0)->orderBy('id')->get();
    }
}
