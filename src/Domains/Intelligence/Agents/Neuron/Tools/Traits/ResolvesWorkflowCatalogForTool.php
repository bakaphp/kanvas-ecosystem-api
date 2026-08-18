<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Collection;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;

/**
 * Resolve the catalogs a workflow rule is assembled from — trigger (`rules_types`), entity
 * (`system_modules`, per app), activities and receivers (`actions`, split by `kind`) — from the
 * free-text names an LLM supplies.
 *
 * Every lookup returns null on a miss instead of throwing, and each has a paired lister so the
 * calling tool can hand the model the valid values as part of its error payload. Never use
 * `SystemModulesRepository::getByModelName()` here: it is a firstOrCreate, so a hallucinated
 * class name would silently register itself as a real module.
 *
 * Listers return descriptions, params and configured state rather than bare names. A name alone
 * forces the model to infer what a step does and whether the tenant can run it — the two guesses
 * that produce a rule which is wired but wrong.
 */
trait ResolvesWorkflowCatalogForTool
{
    use HasKanvasContext;

    private const string SUPPORTED_NAMESPACE = 'Kanvas\\\\%';

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function error(string $message, array $extra = []): array
    {
        return [$this->outcomeKey() => false, 'message' => $message, ...$extra];
    }

    /**
     * The falsey key each tool's callers read — `created` for tools that make something, `updated`
     * for tools that change one. Overridden in the using class, which wins over this default.
     */
    protected function outcomeKey(): string
    {
        return 'created';
    }

    /**
     * The catalog rows behind a rule's steps, in the order they run.
     *
     * @return list<Action>
     */
    protected function actionsOfRule(Rule $rule): array
    {
        return RuleAction::query()
            ->where('rules_id', $rule->getId())
            ->where('is_deleted', 0)
            ->orderBy('weight')
            ->get()
            ->map(fn (RuleAction $ruleAction): ?Action => $ruleAction->activity?->action)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve a comma-separated list of step names to catalog rows, refusing on the first unknown one.
     *
     * @return array{actions: list<Action>}|array{error: array<string, mixed>}
     */
    protected function resolveActionList(string $actions, string $emptyMessage): array
    {
        $resolved = [];

        foreach (array_filter(array_map('trim', explode(',', $actions))) as $actionName) {
            $action = $this->resolveAction($actionName);

            if ($action === null) {
                return ['error' => $this->error(
                    sprintf(
                        '"%s" is not an available workflow activity. Pick one from suggested_actions and retry.',
                        $actionName
                    ),
                    ['suggested_actions' => $this->searchActions($actionName) ?: $this->searchActions()],
                )];
            }

            $resolved[] = $action;
        }

        return $resolved === []
            ? ['error' => $this->error($emptyMessage)]
            : ['actions' => $resolved];
    }

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
        $entity = $this->normalizeClassName($entity);

        if ($entity === '' || ! isset($this->app)) {
            return null;
        }

        $needle = mb_strtolower($entity);

        return SystemModules::query()
            ->fromApp($this->app)
            ->where('model_name', 'like', self::SUPPORTED_NAMESPACE)
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(slug) = ?', [$needle])
                    ->orWhereRaw('LOWER(model_name) = ?', [$needle]);
            })
            ->get()
            ->first(fn (SystemModules $module): bool => $this->isRealEntity($module));
    }

    /**
     * A class name arrives from the model exactly as the model saw it, and it saw it inside JSON —
     * where `Kanvas\Social\…` is written `Kanvas\\Social\\…`. Copying that back verbatim is the
     * correct thing for a model to do; matching it literally is not, and produced the worst possible
     * error: the tool listed an entity and then rejected the very string it had just handed over.
     *
     * Collapsing runs of backslashes is safe because no PHP namespace contains a doubled separator.
     */
    protected function normalizeClassName(string $value): string
    {
        return trim(preg_replace('/\\\\{2,}/', '\\', trim($value)) ?? trim($value));
    }

    /**
     * Entities worth showing someone whose term did not resolve. A fully-qualified class rarely
     * matches as a whole — `Kanvas\Social\Models\Messages` shares no substring with the real
     * `Kanvas\Social\Messages\Models\Message` — so the last segment is tried too, which is the word
     * the caller actually meant.
     *
     * @return list<string>
     */
    protected function suggestEntities(string $entity, int $limit = 25): array
    {
        $entity = trim($entity);
        $matches = $entity === '' ? [] : $this->availableEntities($entity, $limit);

        if ($matches !== []) {
            return $matches;
        }

        $segments = preg_split('/[\\\\ ]+/', $entity) ?: [];
        $lastSegment = trim((string) end($segments));

        if ($lastSegment !== '' && $lastSegment !== $entity) {
            $matches = $this->availableEntities($lastSegment, $limit);
        }

        return $matches !== [] ? $matches : $this->availableEntities(null, $limit);
    }

    /**
     * A module row can name a class that no longer exists — `Kanvas\Social\Models\Messages` sits in
     * the catalog next to the real `Kanvas\Social\Messages\Models\Message` and reads like the more
     * obvious choice. Rules are matched on the triggering record's actual class, so a rule bound to
     * the phantom is never even considered: it looks correct in the database and silently never runs.
     *
     * The namespace check alone does not catch this — the phantom is under `Kanvas\` too.
     */
    protected function isRealEntity(SystemModules $module): bool
    {
        $className = (string) $module->model_name;

        return $className !== '' && class_exists($className);
    }

    /**
     * The list is capped, and a caller reads it as "these are the options" — so what gets cut matters.
     *
     * Ordering by name and taking the first 50 hid the real `Message` entity at position 66 of 77,
     * and the agent, told those were the available entities, concluded none existed. Two fixes: the
     * search matches `model_name` too (a caller naming a class was previously searching only display
     * names), and the class-exists filter runs before the cap rather than eating slots inside it.
     *
     * @return list<string>
     */
    protected function availableEntities(?string $search = null, int $limit = 50): array
    {
        $query = SystemModules::query()
            ->where('model_name', 'like', self::SUPPORTED_NAMESPACE)
            ->orderBy('name');

        if (isset($this->app)) {
            $query->fromApp($this->app);
        }

        $search = $this->normalizeClassName((string) $search);

        if ($search !== '') {
            // MySQL reads `\` as an escape character inside LIKE, so an unescaped class name matches
            // nothing at all — `%Kanvas\Social\…%` looks for `KanvasSocial…`.
            $needle = '%' . str_replace('\\', '\\\\', $search) . '%';
            $query->where(function ($query) use ($needle): void {
                $query->where('name', 'like', $needle)
                    ->orWhere('model_name', 'like', $needle);
            });
        }

        // Over-fetch so the phantom filter removes rows from the candidate pool, not from the answer.
        return $query->limit($limit * 4)->get(['name', 'model_name'])
            ->filter(fn (SystemModules $module): bool => $this->isRealEntity($module))
            ->take($limit)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Workflow steps only. A receiver is not a rule step, so offering one here would produce a rule
     * that can never fire.
     */
    protected function resolveAction(string $name): ?Action
    {
        return $this->resolveActionOfKind($name, ActionKindEnum::WORKFLOW);
    }

    protected function resolveReceiver(string $name): ?Action
    {
        return $this->resolveActionOfKind($name, ActionKindEnum::RECEIVER);
    }

    protected function resolveActionOfKind(string $name, ?ActionKindEnum $kind = null): ?Action
    {
        $name = $this->normalizeClassName($name);

        if ($name === '') {
            return null;
        }

        $needle = mb_strtolower($name);

        $query = Action::query()
            ->where('is_deleted', 0)
            ->where('model_name', 'like', self::SUPPORTED_NAMESPACE)
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(model_name) = ?', [$needle]);
            });

        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }

        return $query->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function searchActions(?string $search = null, int $limit = 25): array
    {
        return $this->catalogQuery(ActionKindEnum::WORKFLOW, $search, $limit)
            ->map(fn (Action $action): array => $this->describeAction($action))
            ->all();
    }

    /**
     * Receiver types the app can be given an inbound endpoint for, each with however many the current
     * company already has wired — the difference between "we could receive this" and "we already do".
     *
     * @return list<array<string, mixed>>
     */
    protected function searchReceivers(?string $search = null, int $limit = 25): array
    {
        $receivers = $this->catalogQuery(ActionKindEnum::RECEIVER, $search, $limit);

        if ($receivers->isEmpty()) {
            return [];
        }

        $configured = isset($this->app) ? $this->configuredReceiverCounts() : [];

        return $receivers->map(fn (Action $action): array => $this->describeAction($action) + [
            'endpoints_configured' => $configured[$action->getId()] ?? 0,
        ])->all();
    }

    /**
     * @return Collection<int, Action>
     */
    protected function catalogQuery(ActionKindEnum $kind, ?string $search = null, int $limit = 25): Collection
    {
        $query = Action::query()
            ->where('is_deleted', 0)
            ->where('kind', $kind->value)
            ->where('model_name', 'like', self::SUPPORTED_NAMESPACE)
            ->orderBy('name');

        if ($search !== null && trim($search) !== '') {
            $needle = '%' . trim($search) . '%';
            $query->where(function ($query) use ($needle): void {
                $query->where('name', 'like', $needle)
                    ->orWhere('description', 'like', $needle)
                    ->orWhere('integration', 'like', $needle);
            });
        }

        return $query->limit($limit)->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function describeAction(Action $action): array
    {
        $entry = ['name' => $action->name];

        if ($action->description !== null && $action->description !== '') {
            $entry['description'] = $action->description;
        } else {
            $entry['description'] = 'No description has been written for this step yet — read the '
                . 'handler or ask a human before using it.';
        }

        if ($action->integration !== null && $action->integration !== '') {
            $entry['integration'] = $action->integration;

            $status = $this->configurationStatus($action);
            $entry['configured'] = $status['configured'];

            if (! $status['configured']) {
                $entry['to_configure'] = $status['how'];
            }
        }

        $params = $action->paramDescriptions();

        if ($params !== []) {
            $entry['params'] = $params;
        }

        $required = $action->requiredParamNames();

        if ($required !== []) {
            $entry['required_params'] = $required;
        }

        return $entry;
    }

    /**
     * Two different things can make a step unusable, and the agent needs to be told which: the tenant
     * has not connected the integration at all, or it has but the settings the step reads are unset.
     * `how` is always an instruction rather than a bare list, because "configured: false" with nothing
     * actionable next to it reads as "avoid this step" — and the steps with no settings of their own
     * are the core ones an agent most needs.
     *
     * Company settings win over app settings, matching how the connectors read their own keys.
     *
     * @return array{configured: bool, how: string}
     */
    protected function configurationStatus(Action $action): array
    {
        if (! isset($this->app)) {
            return [
                'configured' => false,
                'how' => 'This tool has no company context, so it cannot check the integration.',
            ];
        }

        $keys = $action->requiredConfigKeys();

        if ($keys === []) {
            return $this->integrationConnectionStatus((string) $action->integration);
        }

        $company = $this->company ?? null;
        $missing = [];

        foreach ($keys as $key) {
            $value = $company?->get($key) ?? $this->app->get($key);

            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        return [
            'configured' => $missing === [],
            'how' => $missing === []
                ? ''
                : 'Ask an administrator to set these on the company: ' . implode(', ', $missing) . '.',
        ];
    }

    /**
     * A step that declares no settings of its own still needs the integration connected for the
     * company — that is what `executeIntegration` looks up before it runs anything.
     *
     * @return array{configured: bool, how: string}
     */
    protected function integrationConnectionStatus(string $integrationName): array
    {
        $how = sprintf('Connect the "%s" integration for this company.', $integrationName);

        if (! isset($this->company)) {
            return ['configured' => false, 'how' => $how];
        }

        $integration = Integrations::query()->where('name', $integrationName)->first();

        if ($integration === null) {
            return [
                'configured' => false,
                'how' => sprintf('No "%s" integration is registered on this platform.', $integrationName),
            ];
        }

        $connected = IntegrationsCompany::query()
            ->fromCompany($this->company)
            ->where('integrations_id', $integration->getId())
            ->where('is_active', 1)
            ->exists();

        return ['configured' => $connected, 'how' => $connected ? '' : $how];
    }

    /**
     * @return array<int, int> action id => how many endpoints this company has
     */
    protected function configuredReceiverCounts(): array
    {
        $query = ReceiverWebhook::query()
            ->where('is_deleted', 0)
            ->fromApp($this->app);

        if (isset($this->company)) {
            $query->fromCompany($this->company);
        }

        return $query->selectRaw('action_id, COUNT(*) as total')
            ->groupBy('action_id')
            ->pluck('total', 'action_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}
