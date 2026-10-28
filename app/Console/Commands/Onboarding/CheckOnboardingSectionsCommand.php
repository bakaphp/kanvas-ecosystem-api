<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Services\OnboardingAgentTemplateService;

/**
 * Template agents are a precondition nothing builds: every `source_agent` the active sections name,
 * plus the setup agent, must exist at app 0 (what a brand-new app clones from) and, when an app is
 * given, resolve for that app too.
 */
final class CheckOnboardingSectionsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:onboarding:check-sections {app? : app id to check on top of the platform globals at app 0}';

    protected $description = 'Verify every template agent the onboarding sections name exists; exits non-zero naming the missing ones.';

    public function handle(): int
    {
        $templates = new OnboardingAgentTemplateService();
        $missing = $this->report($templates->missingFor(null), 0);

        $appsId = (int) $this->argument('app');
        if ($appsId > 0) {
            $app = Apps::getById($appsId);
            $this->overwriteAppService($app);
            $missing = $this->report($templates->missingFor($app), $appsId) || $missing;
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<string> $missing
     */
    private function report(array $missing, int $appsId): bool
    {
        $scope = $appsId > 0 ? "app {$appsId} (own templates, then app 0)" : 'app 0';
        if ($missing === []) {
            $this->info("Every template agent resolves for {$scope}.");

            return false;
        }

        $this->error(sprintf('Missing template agents (companies_id = 0) for %s: %s', $scope, implode(', ', $missing)));

        return true;
    }
}
