<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;

class SyncAgentToolsCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:sync-tools';

    protected $description = 'Discover #[AgentTool] classes and sync them into the nervous_system_tools catalog (global, apps_id=0).';

    public function handle(AgentToolDiscoveryService $discovery): int
    {
        $this->info('Syncing Agent Tools...');

        $created = [];

        /** @var array<string, int|null> $categoryIds */
        $categoryIds = [];

        foreach ($discovery->discover() as $entry) {
            $categoryId = null;
            if ($entry['category'] !== null) {
                if (! array_key_exists($entry['category'], $categoryIds)) {
                    $categoryIds[$entry['category']] = $this->resolveCategoryId($entry['category']);
                }
                $categoryId = $categoryIds[$entry['category']];
            }

            $tool = Tool::firstOrCreate(
                [
                    'handler' => $entry['class'],
                    'apps_id' => 0,
                ],
                [
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'tool_type' => $entry['toolType'],
                    'frameworks' => $entry['frameworks'],
                    'tool_category_id' => $categoryId,
                    'requires_permission' => $entry['requiresPermission'],
                    'version' => $entry['version'],
                    'is_active' => true,
                    'is_deleted' => false,
                ],
            );

            if ($tool->wasRecentlyCreated) {
                $created[] = $tool->name;
            }
        }

        if ($created !== []) {
            $this->info(count($created) . ' tool(s) created:');
            foreach ($created as $name) {
                $this->line("- {$name}");
            }
        } else {
            $this->info('No new tools were created.');
        }

        $this->info('Syncing Agent Tools Done!');

        return self::SUCCESS;
    }

    private function resolveCategoryId(string $slug): ?int
    {
        $category = ToolCategory::query()
            ->where('slug', $slug)
            ->where('apps_id', 0)
            ->first();

        return $category?->getId();
    }
}
