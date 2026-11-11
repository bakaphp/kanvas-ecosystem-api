<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Actions\PullReceivedEcfsAction;

/**
 * Bills are created as the company's AI agent user when it has one — the AP agent is who works them —
 * otherwise as the company owner.
 */
class PullReceivedEcfsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly int $lookbackDays = 7,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->app->getId() . '-' . $this->company->getId();
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $result = new PullReceivedEcfsAction(
            app: $this->app,
            company: $this->company,
            user: $this->company->getAiAgentUser() ?? $this->company->user,
            lookbackDays: $this->lookbackDays,
        )->execute();

        Log::info('WebPOS received e-CF pull', [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            ...$result,
        ]);
    }
}
