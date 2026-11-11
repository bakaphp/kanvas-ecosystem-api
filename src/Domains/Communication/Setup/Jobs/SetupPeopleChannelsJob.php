<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Setup\Actions\SetupCompanyRulesAction;
use Kanvas\Communication\Setup\Actions\SetupCompanySettingsAction;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Actions\CreatePeopleSocialChannelsAction;
use Kanvas\Guild\Customers\Models\People;

/**
 * Gives every person the company already has the channels and sessions a contact saved from now on
 * gets — the same action, so the two never differ — then switches the agents on and creates the rules
 * that run them. Safe to run again: a channel, session, setting or rule that exists is kept.
 */
final class SetupPeopleChannelsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereHas('contacts')
            ->lazyById(200);

        foreach ($people as $person) {
            new CreatePeopleSocialChannelsAction($person)->execute();
        }

        new SetupCompanySettingsAction($this->app, $this->company)->execute();
        new SetupCompanyRulesAction($this->app, $this->company)->execute();
    }
}
