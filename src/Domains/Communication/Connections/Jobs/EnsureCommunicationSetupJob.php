<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\EnsureCommunicationSetupAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * One pass, never retried by the queue: a failed connection carries its own backoff and the sweep
 * picks it up, so a retry here would only race the sweep.
 */
final class EnsureCommunicationSetupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly IntegrationsCompany $integrationCompany
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new EnsureCommunicationSetupAction($this->app, $this->integrationCompany)->execute();
    }
}
