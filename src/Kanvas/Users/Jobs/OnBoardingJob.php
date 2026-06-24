<?php

declare(strict_types=1);

namespace Kanvas\Users\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Kanvas\ActionEngine\Support\Setup as ActionEngineSetup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Event\Support\Enums\EventSetupTypeEnum;
use Kanvas\Event\Support\Setup as EventSetup;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Guild\Support\Setup as GuildSetup;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany as IntegrationsCompanyDto;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

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
        public Apps $app
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
        $runOnboardingEvent = $this->app->get(AppSettingsEnums::ONBOARDING_EVENT_SETUP->getValue());
        $runOnboardingActionEngine = $this->app->get(AppSettingsEnums::ONBOARDING_ACTION_ENGINE_SETUP->getValue());
        $runOnboarding = $runOnboardingGuild || $runOnboardingInventory || $runOnboardingActionEngine;

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

            try {
                $this->setupDefaultIntegration($company, $this->app);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($runOnboardingEvent) {
            $eventSetupType = EventSetupTypeEnum::tryFrom(
                (string) $this->app->get(AppSettingsEnums::ONBOARDING_EVENT_SETUP_TYPE->getValue())
            ) ?? EventSetupTypeEnum::STANDARD;

            new EventSetup(
                $this->app,
                $this->user,
                $company,
                $eventSetupType
            )->run();
        }

        if ($runOnboardingActionEngine) {
            $fromCompanyId = $this->app->get(AppSettingsEnums::ONBOARDING_ACTION_ENGINE_SETUP_FROM_COMPANY->getValue());
            $fromCompany = $fromCompanyId ? Companies::getById((int) $fromCompanyId) : null;

            new ActionEngineSetup(
                $this->app,
                $this->user,
                $company,
                [],
                $fromCompany
            )->run();
        }
    }

    private function setupDefaultIntegration(Companies $company, Apps $app): void
    {
        $integration = Integrations::getByName(IntegrationsEnum::INTERNAL->value);
        $defaultRegion = $company->defaultRegion()->first();

        if (! $integration || ! $defaultRegion) {
            return;
        }

        $integrationDto = new IntegrationsCompanyDto(
            integration: $integration,
            region: $defaultRegion,
            company: $company,
            config: [],
            app: $app
        );

        if (! class_exists($handler = $integration->handler)) {
            throw new InternalServerErrorException('Handler Class not found.');
        }

        $status = Status::where('slug', StatusEnum::ACTIVE->value)
                        ->where('apps_id', 0)
                        ->first();

        new CreateIntegrationCompanyAction(
            $integrationDto,
            $company->user,
            $status
        )->execute();
    }
}
