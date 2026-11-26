<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a rule ran per company now listens for the event itself: the new-lead reach-out, the AI
 * Assist answer, a lead's AI mode, indexing an agent's documents and pruning a deleted file from
 * the knowledge base. Their rules would run them a second time, so they go.
 *
 * The reach-out is the one with a switch to carry over: a rule that only said "reach out to every
 * new lead" becomes `reach_out_config.new_leads` on the agent it ran as. A rule a person narrowed
 * (its own conditions or params) is left running as a rule, with its agent written on it, and
 * switches nothing on.
 */
return new class () extends Migration {
    private const array REACH_OUT = [
        'Kanvas\\Connectors\\SalesAssist\\Activities\\SalesAssistAgentReachOutActivity',
        'Kanvas\\Intelligence\\Agents\\Activities\\AgentReachOutActivity',
    ];
    private const string TRIGGER_INTELLIGENCE = 'Kanvas\\Intelligence\\Triggers\\Workflows\\TriggerIntelligenceActivity';
    private const array REMOVED_ACTIVITIES = [
        'Kanvas\\Connectors\\SalesAssist\\Activities\\AIAssistChannelResponderActivity',
        'Kanvas\\Intelligence\\Knowledge\\Workflows\\IndexKnowledgeDocumentActivity',
        'Kanvas\\Intelligence\\Knowledge\\Workflows\\PruneKnowledgeDocumentActivity',
    ];
    private const string REACH_OUT_SETTING = 'agent_reach_out_default_agent_id';
    private const array PLAIN_REACH_OUT_PARAMS = ['trigger_type', 'agent_id', 'lead_source_allowlist'];

    public function up(): void
    {
        $this->addReachOutConfigColumn();
        $this->moveReachOutToAgents();
        $this->removeSteps($this->actionIds(self::TRIGGER_INTELLIGENCE), ruleType: 'trigger-ai');

        $removed = $this->actionIds(...self::REMOVED_ACTIVITIES);
        $this->removeSteps($removed);
        DB::connection('workflow')->table('actions')->whereIn('id', $removed)->update(['is_deleted' => 1]);
    }

    public function down(): void
    {
        // The removed activity classes no longer exist; there is nothing to restore the rows for.
    }

    /**
     * `migrate-all-kanvas` runs the Workflow migrations before the Intelligence ones, so the column
     * this writes is not there yet on a database that is being upgraded. The Intelligence migration
     * that owns the column skips it when it already exists. A fresh database has no agents table at
     * this point and no rule to move either.
     */
    private function addReachOutConfigColumn(): void
    {
        $schema = Schema::connection('intelligence');

        if (! $schema->hasTable('agents') || $schema->hasColumn('agents', 'reach_out_config')) {
            return;
        }

        $schema->table('agents', function (Blueprint $table): void {
            $table->json('reach_out_config')->nullable();
        });
    }

    private function moveReachOutToAgents(): void
    {
        $workflow = DB::connection('workflow');
        $rules = $workflow->table('rules')
            ->whereIn('id', $this->ruleIdsRunning($this->actionIds(...self::REACH_OUT)))
            ->where('rules_types_id', $this->ruleTypeId('created'))
            ->where('is_deleted', 0)
            ->get();

        foreach ($rules as $rule) {
            $params = $this->decode($rule->params);

            if (! $this->isPlainReachOut((int) $rule->id, $params)) {
                $this->keepRunningAsARule($rule, $params);

                continue;
            }

            $agentIds = isset($params['agent_id'])
                ? [(int) $params['agent_id']]
                : $this->agentIdsFromCompanySettings((int) $rule->apps_id, (int) $rule->companies_id);

            $switchedOn = 0;
            foreach ($agentIds as $agentId) {
                $switchedOn += (int) $this->switchOnReachOut(
                    $agentId,
                    (int) $rule->apps_id,
                    (int) $rule->companies_id,
                    (array) ($params['lead_source_allowlist'] ?? [])
                );
            }

            // A rule with no agent behind it never reached anyone; it stays for a person to look at.
            if ($switchedOn > 0) {
                $workflow->table('rules_actions')->where('rules_id', $rule->id)->update(['is_deleted' => 1]);
                $workflow->table('rules')->where('id', $rule->id)->update(['is_deleted' => 1]);
            }
        }
    }

    /**
     * A narrowed rule found its agent in the company's setting, which nothing reads now, so the
     * agent goes on the rule. The company's agents are marked as not reaching out on their own:
     * setup switches reach-out on for a company where no agent has a say yet, and that would reach
     * every new lead beside the rule that narrows it. An app-wide rule is left for a person: each
     * of its companies named its own agent.
     *
     * @param array<string, mixed> $params
     */
    private function keepRunningAsARule(object $rule, array $params): void
    {
        $appId = (int) $rule->apps_id;
        $companyId = (int) $rule->companies_id;

        if ($companyId === 0) {
            return;
        }

        $agentId = $params['agent_id'] ?? $this->agentIdsFromCompanySettings($appId, $companyId)[0] ?? null;

        if ($agentId !== null && ! isset($params['agent_id'])) {
            DB::connection('workflow')->table('rules')
                ->where('id', $rule->id)
                ->update(['params' => json_encode($params + ['agent_id' => (int) $agentId])]);
        }

        DB::connection('intelligence')->table('agents')
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', 0)
            ->whereNull('reach_out_config')
            ->update(['reach_out_config' => json_encode(['new_leads' => false])]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isPlainReachOut(int $ruleId, array $params): bool
    {
        if (array_diff(array_keys($params), self::PLAIN_REACH_OUT_PARAMS) !== []) {
            return false;
        }

        $workflow = DB::connection('workflow');
        $otherSteps = $workflow->table('rules_actions')
            ->where('rules_id', $ruleId)
            ->where('is_deleted', 0)
            ->whereNotIn(
                'rules_workflow_actions_id',
                $workflow->table('rules_workflow_actions')
                    ->whereIn('actions_id', $this->actionIds(self::TRIGGER_INTELLIGENCE, ...self::REACH_OUT))
                    ->select('id')
            )
            ->exists();

        if ($otherSteps) {
            return false;
        }

        return $workflow->table('rules_conditions')
            ->where('rules_id', $ruleId)
            ->where('is_deleted', 0)
            ->get()
            ->every(fn (object $condition): bool => $condition->attribute_name === 'id'
                && $condition->operator === '>'
                && (string) $condition->value === '0');
    }

    /**
     * @return list<int> the company's reach-out agent, or every company's for an app-wide rule
     */
    private function agentIdsFromCompanySettings(int $appId, int $companyId): array
    {
        $settings = DB::connection('ecosystem')->table('companies_settings')
            ->where('name', self::REACH_OUT_SETTING)
            ->when($companyId > 0, fn ($query) => $query->where('companies_id', $companyId))
            ->pluck('value', 'companies_id');

        $agentIds = $settings
            ->map(fn (mixed $value): int => (int) trim((string) $value, '"'))
            ->filter()
            ->unique()
            ->values();

        return DB::connection('intelligence')->table('agents')
            ->whereIn('id', $agentIds)
            ->where('apps_id', $appId)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Only an agent of the rule's own company, or one the app shares. Two rules on one agent reach
     * the union of their leads, and a rule with no list reaches them all.
     *
     * @param list<string> $leadSources
     */
    private function switchOnReachOut(
        int $agentId,
        int $appId,
        int $companyId,
        array $leadSources
    ): bool {
        $agents = DB::connection('intelligence')->table('agents');
        $agent = (clone $agents)
            ->where('id', $agentId)
            ->where('apps_id', $appId)
            ->when($companyId > 0, fn ($query) => $query->whereIn('companies_id', [$companyId, 0]))
            ->where('is_deleted', 0)
            ->first();

        if ($agent === null) {
            return false;
        }

        $config = $this->decode($agent->reach_out_config);
        $reachesEveryLead = ($config['new_leads'] ?? false) === true && empty($config['lead_source_allowlist']);
        $config['new_leads'] = true;

        if ($leadSources === [] || $reachesEveryLead) {
            unset($config['lead_source_allowlist']);
        } else {
            $config['lead_source_allowlist'] = array_values(array_unique([
                ...(array) ($config['lead_source_allowlist'] ?? []),
                ...$leadSources,
            ]));
        }

        $agents->where('id', $agentId)->update(['reach_out_config' => json_encode($config)]);

        return true;
    }

    /**
     * Takes the activities out of every rule that runs them, and removes a rule left with no step.
     *
     * @param Collection<int, int> $actionIds
     */
    private function removeSteps(Collection $actionIds, ?string $ruleType = null): void
    {
        $workflow = DB::connection('workflow');
        $stepIds = $workflow->table('rules_workflow_actions')->whereIn('actions_id', $actionIds)->pluck('id');
        $ruleIds = $workflow->table('rules')
            ->whereIn('id', $this->ruleIdsRunning($actionIds))
            ->when($ruleType !== null, fn ($query) => $query->where('rules_types_id', $this->ruleTypeId($ruleType)))
            ->where('is_deleted', 0)
            ->pluck('id');

        $workflow->table('rules_actions')
            ->whereIn('rules_id', $ruleIds)
            ->whereIn('rules_workflow_actions_id', $stepIds)
            ->update(['is_deleted' => 1]);

        $stillRunning = $workflow->table('rules_actions')
            ->whereIn('rules_id', $ruleIds)
            ->where('is_deleted', 0)
            ->pluck('rules_id');

        $workflow->table('rules')
            ->whereIn('id', $ruleIds->diff($stillRunning))
            ->update(['is_deleted' => 1]);
    }

    /**
     * @return Collection<int, int>
     */
    private function actionIds(string ...$activities): Collection
    {
        return DB::connection('workflow')->table('actions')->whereIn('model_name', $activities)->pluck('id');
    }

    /**
     * @param Collection<int, int> $actionIds
     *
     * @return Collection<int, int>
     */
    private function ruleIdsRunning(Collection $actionIds): Collection
    {
        $workflow = DB::connection('workflow');

        return $workflow->table('rules_actions')
            ->whereIn(
                'rules_workflow_actions_id',
                $workflow->table('rules_workflow_actions')->whereIn('actions_id', $actionIds)->select('id')
            )
            ->where('is_deleted', 0)
            ->pluck('rules_id');
    }

    private function ruleTypeId(string $name): int
    {
        return (int) DB::connection('workflow')->table('rules_types')->where('name', $name)->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        // A column written through a string cast holds JSON inside a JSON string.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }
};
