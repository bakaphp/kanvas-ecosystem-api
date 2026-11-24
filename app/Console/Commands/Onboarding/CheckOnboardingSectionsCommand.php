<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Services\OnboardingAgentTemplateService;

/**
 * Every catalog agent, plus the setup agent, must resolve to a template row at app 0 or to a synced
 * agent type (`kanvas:intelligence:sync-agent-types`) and, when an app is given, for that app too.
 */
final class CheckOnboardingSectionsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:onboarding:check-sections {app? : app id to check on top of the platform globals at app 0}';

    protected $description = 'Verify every onboarding catalog agent has a template row or an agent type; exits non-zero naming the missing ones.';

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
            $this->info("Every catalog agent resolves for {$scope}.");

            return false;
        }

        $this->error(sprintf('Agents with neither a template row (companies_id = 0) nor an agent type for %s: %s', $scope, implode(', ', $missing)));

        return true;
    }
}
