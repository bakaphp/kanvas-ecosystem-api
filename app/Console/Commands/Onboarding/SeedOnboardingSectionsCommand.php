<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Services\OnboardingAgentTemplateService;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsSeedService;

/**
 * Upserts the onboarding cards from a reviewed JSON file. App 0 is the platform default every new
 * app reads; a specific app gets its own rows, which override the globals by slug.
 */
final class SeedOnboardingSectionsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:onboarding:seed-sections {app=0 : app id, 0 = platform-global} {--from= : JSON file, defaults to database/seeders/data/onboarding/global.json}';

    protected $description = 'Seed the onboarding sections catalog for an app (or the platform globals at app 0) and check the agent templates it names exist.';

    public function handle(): int
    {
        $appsId = (int) $this->argument('app');
        $app = $appsId > 0 ? Apps::getById($appsId) : null;
        if ($app !== null) {
            $this->overwriteAppService($app);
        }

        $counts = new OnboardingSectionsSeedService()->seed($appsId, $this->option('from') ?: null);
        $this->info(sprintf('Sections for app %d: %d created, %d updated.', $appsId, $counts['created'], $counts['updated']));

        $missing = new OnboardingAgentTemplateService()->missingFor($app);
        if ($missing !== []) {
            $this->error(sprintf(
                'Agents with neither a template row (companies_id = 0) nor an agent type for app %d%s: %s',
                $appsId,
                $appsId > 0 ? ' and app 0' : '',
                implode(', ', $missing),
            ));

            return self::FAILURE;
        }

        $this->info('Every catalog agent resolves to a template or an agent type.');

        return self::SUCCESS;
    }
}
