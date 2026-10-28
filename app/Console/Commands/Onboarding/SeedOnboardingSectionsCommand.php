<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingAgentTemplateService;
use Kanvas\Workflow\Models\Integrations;

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

        $path = (string) ($this->option('from') ?: database_path('seeders/data/onboarding/global.json'));
        $sections = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['sections'] ?? [];

        $created = 0;
        $updated = 0;
        foreach ($sections as $definition) {
            $config = $this->resolveIntegrationIds((array) ($definition['config'] ?? []), $appsId);
            $attributes = [
                'label' => (string) $definition['label'],
                'title' => (string) $definition['title'],
                'description' => $definition['description'] ?? null,
                'icon' => $definition['icon'] ?? null,
                'is_available' => (bool) ($definition['is_available'] ?? true),
                'display_order' => (int) ($definition['display_order'] ?? 0),
                'config' => $config,
                'is_active' => true,
                'is_deleted' => false,
            ];

            $row = OnboardingSection::query()
                ->where('apps_id', $appsId)
                ->where('slug', (string) $definition['slug'])
                ->first();

            if ($row === null) {
                OnboardingSection::query()->create($attributes + ['apps_id' => $appsId, 'slug' => (string) $definition['slug']]);
                $created++;
            } else {
                $row->fill($attributes)->saveOrFail();
                $updated++;
            }
        }

        $this->info(sprintf('Sections for app %d: %d created, %d updated.', $appsId, $created, $updated));

        $missing = new OnboardingAgentTemplateService()->missingFor($app);
        if ($missing !== []) {
            $this->error(sprintf(
                'Missing template agents (companies_id = 0) for app %d%s: %s',
                $appsId,
                $appsId > 0 ? ' and app 0' : '',
                implode(', ', $missing),
            ));

            return self::FAILURE;
        }

        $this->info('Every template agent resolves.');

        return self::SUCCESS;
    }

    /**
     * Options name integrations by `integration_name` so the file is portable; the id is resolved
     * here against the app's rows, then the globals. An unknown name keeps the option and leaves
     * the id null — the card still renders, nothing can report it connected.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveIntegrationIds(array $config, int $appsId): array
    {
        if (! isset($config['requirements']) || ! is_array($config['requirements'])) {
            return $config;
        }

        foreach ($config['requirements'] as &$requirement) {
            if (! isset($requirement['options']) || ! is_array($requirement['options'])) {
                continue;
            }

            foreach ($requirement['options'] as &$option) {
                if (empty($option['integration_name']) || ! empty($option['integration_id'])) {
                    continue;
                }

                $integration = Integrations::query()
                    ->where('name', (string) $option['integration_name'])
                    ->whereIn('apps_id', [0, $appsId])
                    ->where('is_deleted', 0)
                    ->orderByDesc('apps_id')
                    ->first();

                $option['integration_id'] = $integration?->getId();
            }
            unset($option);
        }
        unset($requirement);

        return $config;
    }
}
