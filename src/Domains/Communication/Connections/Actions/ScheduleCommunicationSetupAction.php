<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * The trigger: completing a messaging integration is the only thing a tenant does. Called inside
 * the transaction that saves the integration, so the connection rows it writes commit with the
 * selection they come from; the vendor work runs after commit, in a job.
 */
final class ScheduleCommunicationSetupAction
{
    /**
     * Takes any app: every integration save comes through here, and only a messaging one needs the
     * app as a model — resolved after that is known.
     */
    public function __construct(
        private readonly AppInterface $app,
        private readonly IntegrationsCompany $integrationCompany
    ) {
    }

    public function execute(): bool
    {
        $provider = app(ProviderRegistry::class)->forIntegration($this->integrationCompany);

        if ($provider === null || (int) $this->integrationCompany->apps_id !== $this->app->getId()) {
            return false;
        }

        $app = $this->app instanceof Apps ? $this->app : Apps::query()->findOrFail($this->app->getId());

        new EnsureCommunicationSetupAction($app, $this->integrationCompany)->reconcile($provider);

        DB::connection('workflow')->afterCommit(
            fn () => EnsureCommunicationSetupJob::dispatch($app, $this->integrationCompany)
        );

        return true;
    }
}
