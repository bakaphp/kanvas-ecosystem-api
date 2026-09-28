<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Tools;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;

class SyncAgentToolsCommand extends Command
{
    /**
     * Pivot/grant tables (all keyed by tool_id) that make a catalog row "in use" by an agent or
     * agent type. A duplicate row referenced by any of these is never pruned.
     */
    private const REFERENCE_TABLES = [
        'nervous_system_agent_tools',
        'nervous_system_agent_selected_tools',
        'nervous_system_tool_agent_types',
        'nervous_system_tool_kanvas_modules',
    ];

    protected $signature = 'kanvas:nervous-system:sync-tools '
        . '{--force : Update existing tool rows (name, description, category, frameworks, ...) instead of only creating new ones} '
        . '{--prune : Delete duplicate catalog rows (same handler + apps_id) that are NOT referenced by any agent, agent type or module, keeping one canonical survivor per handler}';

    protected $description = 'Discover #[AgentTool] classes and sync them into the nervous_system_tools catalog (global, apps_id=0).';

    public function handle(AgentToolDiscoveryService $discovery): int
    {
        $force = (bool) $this->option('force');

        $this->info($force ? 'Syncing Agent Tools (force update)...' : 'Syncing Agent Tools...');

        $created = [];
        $updated = [];

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

            $attributes = [
                'name' => $entry['name'],
                'description' => $entry['description'],
                'tool_type' => $entry['toolType'],
                'frameworks' => $entry['frameworks'],
                'tool_category_id' => $categoryId,
                'requires_permission' => $entry['requiresPermission'],
                'version' => $entry['version'],
                'is_active' => true,
                'is_deleted' => false,
            ];

            $identity = [
                'handler' => $entry['class'],
                'apps_id' => 0,
            ];

            if ($force) {
                $tool = Tool::updateOrCreate($identity, $attributes);
                match (true) {
                    $tool->wasRecentlyCreated => $created[] = $tool->name,
                    $tool->wasChanged() => $updated[] = $tool->name,
                    default => null,
                };

                continue;
            }

            $tool = Tool::firstOrCreate($identity, $attributes);
            if ($tool->wasRecentlyCreated) {
                $created[] = $tool->name;
            }
        }

        $this->report('created', $created);
        if ($force) {
            $this->report('updated', $updated);
        }

        if ($this->option('prune')) {
            $this->pruneDuplicates();
        }

        $this->info('Syncing Agent Tools Done!');

        return self::SUCCESS;
    }

    /**
     * Delete duplicate rows (same handler + apps_id) that no agent, agent type or module references.
     * All referenced rows and agent-owned (agents_id) rows are kept, and one canonical survivor per
     * handler is always kept even when nothing references it — so the catalog never loses a tool.
     */
    private function pruneDuplicates(): void
    {
        $connection = DB::connection('intelligence');

        $referencedToolIds = $this->referencedToolIds($connection);

        $duplicateGroups = $connection->table('nervous_system_tools')
            ->select('handler', 'apps_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('handler')
            ->groupBy('handler', 'apps_id')
            ->having('total', '>', 1)
            ->get();

        $deleteIds = [];
        $keptInUse = 0;

        foreach ($duplicateGroups as $group) {
            $rows = $connection->table('nervous_system_tools')
                ->where('handler', $group->handler)
                ->where('apps_id', $group->apps_id)
                ->get(['id', 'tool_category_id', 'agents_id', 'is_deleted']);

            $inUse = $rows->filter(
                fn ($r): bool => $r->agents_id !== null || isset($referencedToolIds[$r->id]),
            );
            $keptInUse += $inUse->count();

            $survivor = $this->pickSurvivor($rows);

            foreach ($rows as $r) {
                if ($r->id === $survivor->id || $r->agents_id !== null || isset($referencedToolIds[$r->id])) {
                    continue;
                }
                $deleteIds[] = $r->id;
            }
        }

        if ($deleteIds === []) {
            $this->info('No duplicate tools to prune.');

            return;
        }

        $connection->table('nervous_system_tools')->whereIn('id', $deleteIds)->delete();

        $this->info(count($deleteIds) . ' duplicate tool row(s) pruned (' . $keptInUse . ' in-use row(s) kept).');
    }

    /**
     * @return array<int, true> tool_id => true, for O(1) membership checks
     */
    private function referencedToolIds(Connection $connection): array
    {
        $ids = [];
        foreach (self::REFERENCE_TABLES as $table) {
            foreach ($connection->table($table)->distinct()->pluck('tool_id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        return $ids;
    }

    /**
     * The row to keep as the canonical catalog entry: prefer a categorized, live (not soft-deleted)
     * row (that's the one `sync` maintains), falling back to the lowest id.
     *
     * @param Collection<int, object> $rows
     */
    private function pickSurvivor(Collection $rows): object
    {
        return $rows->sort(fn ($a, $b): int => [
            $a->tool_category_id !== null ? 0 : 1,
            (int) $a->is_deleted,
            (int) $a->id,
        ] <=> [
            $b->tool_category_id !== null ? 0 : 1,
            (int) $b->is_deleted,
            (int) $b->id,
        ])->first();
    }

    /**
     * @param string[] $names
     */
    private function report(string $verb, array $names): void
    {
        if ($names === []) {
            $this->info("No tools were {$verb}.");

            return;
        }

        $this->info(count($names) . " tool(s) {$verb}:");
        foreach ($names as $name) {
            $this->line("- {$name}");
        }
    }

    /**
     * Resolve the category slug to its id, creating the global category row if it doesn't exist yet
     * so a tool that declares a brand-new category is never silently left uncategorized.
     */
    private function resolveCategoryId(string $slug): ?int
    {
        $category = ToolCategory::firstOrCreate(
            [
                'slug' => $slug,
                'apps_id' => 0,
            ],
            [
                'name' => Str::headline($slug),
                'is_active' => true,
                'is_deleted' => false,
            ],
        );

        return $category->getId();
    }
}
