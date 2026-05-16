<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\NervousSystem\Capability\Contracts\DeclaresKanvasModules;
use Kanvas\NervousSystem\Capability\Models\Tool;

class ReconcileToolKanvasModulesAction
{
    public function __construct(
        protected readonly Tool $tool,
    ) {
    }

    /**
     * @return array{added: int, updated: int, removed: int}
     */
    public function execute(): array
    {
        $handler = $this->tool->handler;
        if ($handler === null || $handler === '' || ! class_exists($handler)) {
            return ['added' => 0, 'updated' => 0, 'removed' => 0];
        }

        if (! is_subclass_of($handler, DeclaresKanvasModules::class)) {
            return ['added' => 0, 'updated' => 0, 'removed' => 0];
        }

        $declared = $this->normalize($handler::kanvasModules());

        $existing = DB::connection('intelligence')
            ->table('nervous_system_tool_kanvas_modules')
            ->where('tool_id', $this->tool->getId())
            ->get()
            ->keyBy('kanvas_modules_id');

        $added = 0;
        $updated = 0;
        $now = now();

        foreach ($declared as $moduleId => $direction) {
            $existingRow = $existing->get($moduleId);
            if ($existingRow === null) {
                DB::connection('intelligence')
                    ->table('nervous_system_tool_kanvas_modules')
                    ->insert([
                        'tool_id' => $this->tool->getId(),
                        'kanvas_modules_id' => $moduleId,
                        'direction' => $direction,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                $added++;

                continue;
            }

            if ($existingRow->direction !== $direction) {
                DB::connection('intelligence')
                    ->table('nervous_system_tool_kanvas_modules')
                    ->where('tool_id', $this->tool->getId())
                    ->where('kanvas_modules_id', $moduleId)
                    ->update(['direction' => $direction, 'updated_at' => $now]);
                $updated++;
            }
        }

        $toRemove = $existing->keys()->diff(array_keys($declared));
        $removed = 0;
        if ($toRemove->isNotEmpty()) {
            $removed = DB::connection('intelligence')
                ->table('nervous_system_tool_kanvas_modules')
                ->where('tool_id', $this->tool->getId())
                ->whereIn('kanvas_modules_id', $toRemove->all())
                ->delete();
        }

        return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    /**
     * @param array<int, KanvasModuleEnum|array{module: KanvasModuleEnum, direction?: string}> $declared
     * @return array<int, string> map of kanvas_modules_id => direction
     */
    private function normalize(array $declared): array
    {
        $out = [];
        foreach ($declared as $entry) {
            if ($entry instanceof KanvasModuleEnum) {
                $out[$entry->value] = 'consumes';

                continue;
            }
            if (is_array($entry) && isset($entry['module']) && $entry['module'] instanceof KanvasModuleEnum) {
                $direction = $entry['direction'] ?? 'consumes';
                if (! in_array($direction, ['consumes', 'produces', 'both'], true)) {
                    $direction = 'consumes';
                }
                $out[$entry['module']->value] = $direction;
            }
        }

        return $out;
    }
}
