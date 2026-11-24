<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Setup\Actions\SetUpAccountingAgentAction;
use Kanvas\Users\Models\Users;

/**
 * The hire path: an AP/AR agent hired outside onboarding sets the company's accounting up in the
 * background, so the hire itself never waits on Mailgun or the chart seeder.
 */
final class SetUpAccountingAgentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly ?Users $approver = null,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new SetUpAccountingAgentAction(
            app: $this->app,
            company: $this->company,
            approver: $this->approver,
        )->execute();
    }
}
