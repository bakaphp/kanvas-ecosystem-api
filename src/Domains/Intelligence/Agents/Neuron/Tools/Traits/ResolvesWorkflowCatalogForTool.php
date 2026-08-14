<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\RuleType;

/**
 * Resolve the three catalogs a workflow rule is assembled from — trigger (`rules_types`),
 * entity (`system_modules`, per app) and activities (`actions`) — from the free-text names an
 * LLM supplies.
 *
 * Every lookup returns null on a miss instead of throwing, and each has a paired lister so the
 * calling tool can hand the model the valid values as part of its error payload. Never use
 * `SystemModulesRepository::getByModelName()` here: it is a firstOrCreate, so a hallucinated
 * class name would silently register itself as a real module.
 */
trait ResolvesWorkflowCatalogForTool
{
    use HasKanvasContext;

    protected function resolveRuleType(string $trigger): ?RuleType
    {
        $trigger = trim($trigger);

        if ($trigger === '') {
            return null;
        }

        return RuleType::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($trigger)])
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * @return list<string>
     */
    protected function availableTriggers(): array
    {
        return RuleType::query()
            ->where('is_deleted', 0)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    protected function resolveSystemModule(string $entity): ?SystemModules
    {
        $entity = trim($entity);

        if ($entity === '' || ! isset($this->app)) {
            return null;
        }

        $needle = mb_strtolower($entity);

        return SystemModules::query()
            ->fromApp($this->app)
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(slug) = ?', [$needle])
                    ->orWhereRaw('LOWER(model_name) = ?', [$needle]);
            })
            ->first();
    }

    /**
     * @return list<string>
     */
    protected function availableEntities(?string $search = null, int $limit = 50): array
    {
        $query = SystemModules::query()->orderBy('name');

        if (isset($this->app)) {
            $query->fromApp($this->app);
        }

        if ($search !== null && trim($search) !== '') {
            $query->where('name', 'like', '%' . trim($search) . '%');
        }

        return $query->limit($limit)->pluck('name')->all();
    }

    protected function resolveAction(string $name): ?Action
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $needle = mb_strtolower($name);

        return Action::query()
            ->where('is_deleted', 0)
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(model_name) = ?', [$needle]);
            })
            ->first();
    }

    /**
     * @return list<string>
     */
    protected function searchActions(?string $search = null, int $limit = 25): array
    {
        $query = Action::query()->where('is_deleted', 0)->orderBy('name');

        if ($search !== null && trim($search) !== '') {
            $query->where('name', 'like', '%' . trim($search) . '%');
        }

        return $query->limit($limit)->pluck('name')->all();
    }
}
