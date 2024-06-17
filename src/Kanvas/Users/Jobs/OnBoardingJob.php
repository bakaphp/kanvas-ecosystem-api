<?php

declare(strict_types=1);

namespace Kanvas\Users\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Guild\Support\Setup as GuildSetup;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter as ImporterDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;

class OnBoardingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public $failOnTimeout = false;
    public $timeout = 120000;

    public function __construct(
        public UserInterface $user,
        public CompaniesBranches $branch,
        public AppInterface $app
    ) {
    }

    /**
     * handle.
     *
     * @return void
     */
    public function handle()
    {
        $runOnboardingGuild = $this->app->get(AppSettingsEnums::ONBOARDING_GUILD_SETUP->getValue());
        $runOnboardingInventory = $this->app->get(AppSettingsEnums::ONBOARDING_INVENTORY_SETUP->getValue());
        $runOnboarding = $runOnboardingGuild || $runOnboardingInventory;

        if (! $runOnboarding) {
            return;
        }

        config(['laravel-model-caching.disabled' => true]);
        Auth::loginUsingId($this->user->getId());
        $this->overwriteAppService($this->app);
        $this->overwriteAppServiceLocation($this->branch);

        /**
         * @var Companies
         */
        $company = $this->branch->company()->firstOrFail();

        if ($runOnboardingGuild) {
            (new GuildSetup(
                $this->app,
                $this->user,
                $company
            ))->run();
        }

        if ($runOnboardingInventory) {
            (new InventorySetup(
                $this->app,
                $this->user,
                $company
            ))->run();
        }
    }
}
