<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Illuminate\Support\Facades\Schema;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Upserts the catalog cards from a JSON file by (apps_id, slug), so a later run updates a card's
 * config in place. The command and the catalog-refresh migrations share it.
 */
final class OnboardingSectionsSeedService
{
    private ?bool $integrationsAvailable = null;

    public static function defaultPath(): string
    {
        return database_path('seeders/data/onboarding/global.json');
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function seed(int $appsId, ?string $path = null): array
    {
        $file = $path ?: self::defaultPath();
        $sections = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['sections'] ?? [];

        $created = 0;
        $updated = 0;
        foreach ($sections as $definition) {
            $attributes = [
                'label' => (string) $definition['label'],
                'title' => (string) $definition['title'],
                'description' => $definition['description'] ?? null,
                'icon' => $definition['icon'] ?? null,
                'is_available' => (bool) ($definition['is_available'] ?? true),
                'display_order' => (int) ($definition['display_order'] ?? 0),
                'config' => $this->resolveIntegrationIds((array) ($definition['config'] ?? []), $appsId),
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

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Options name integrations by `integration_name` so the file is portable; the id is resolved
     * against the app's rows, then the globals. An unknown name keeps the option and leaves the id
     * null: the card still renders, nothing can report it connected.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveIntegrationIds(array $config, int $appsId): array
    {
        if (! isset($config['requirements']) || ! is_array($config['requirements']) || ! $this->integrationsAvailable()) {
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

    /**
     * The workflow database may not exist yet when a migration runs on a fresh database, and asking
     * for a table on a missing database throws from the connector, not from hasTable().
     */
    private function integrationsAvailable(): bool
    {
        if ($this->integrationsAvailable !== null) {
            return $this->integrationsAvailable;
        }

        try {
            return $this->integrationsAvailable = Schema::connection('workflow')->hasTable('integrations');
        } catch (Throwable) {
            return $this->integrationsAvailable = false;
        }
    }
}
