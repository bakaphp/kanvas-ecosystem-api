<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Services;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;

/**
 * The deterministic half of the supervisor: compares a dealer's runtime against what SalesAssist needs
 * to operate. No model decides these, a setting is either there or it is not.
 *
 * A finding names keys and ids only, never a value: its text reaches the model, the ledger and whoever
 * reads the report, and these companies keep credentials in the same settings table.
 */
final class SupervisorConfigurationAuditService
{
    public const string SEVERITY_CRITICAL = 'critical';
    public const string SEVERITY_HIGH = 'high';
    public const string SEVERITY_MEDIUM = 'medium';

    /** Shorter names match ordinary words in a prompt. */
    private const int MIN_TENANT_NAME_LENGTH = 5;

    private const array REQUIRED_SETTINGS = [
        'work_hours' => self::SEVERITY_HIGH,
        'working_days' => self::SEVERITY_HIGH,
        'adf_sources' => self::SEVERITY_HIGH,
    ];

    private const array AGENT_SETTINGS = [
        'ai_assist_agent',
    ];

    /** @var list<array{code: string, severity: string, summary: string, evidence: array<string, mixed>}> */
    private array $findings = [];

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @return list<array{code: string, severity: string, summary: string, evidence: array<string, mixed>}>
     */
    public function execute(): array
    {
        $this->findings = [];

        // Not fromCompany(): with an app key bound it widens to every company, and this compares tenants.
        $agents = Agent::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->notDeleted()
            ->get();
        $agentsById = $agents->keyBy('id')->all();
        $rules = $this->rules();

        $this->auditCompanyRecord();
        $this->auditRequiredSettings();
        $this->auditAgentSettings($agentsById);
        $this->auditReachOut($rules);
        $this->auditPipelines();
        $this->auditCrossTenantPrompts($agents->all());

        return [
            ...$this->findings,
            ...new SupervisorFollowUpAuditService($this->app, $this->company)->execute(),
        ];
    }

    private function auditCompanyRecord(): void
    {
        if ($this->company->getTimezone() === null) {
            $this->add(
                'company.timezone_missing',
                self::SEVERITY_HIGH,
                'The company has no valid timezone, so work hours and the supervisor window cannot be computed.',
            );
        }
    }

    private function auditRequiredSettings(): void
    {
        foreach (self::REQUIRED_SETTINGS as $key => $severity) {
            if (empty($this->company->get($key))) {
                $this->add(
                    "setting.{$key}_missing",
                    $severity,
                    "Company setting {$key} is not configured.",
                    ['setting' => $key],
                );
            }
        }
    }

    /**
     * @param array<int, Agent> $agents
     */
    private function auditAgentSettings(array $agents): void
    {
        foreach (self::AGENT_SETTINGS as $key) {
            $agentId = (int) $this->company->get($key);

            if ($agentId !== 0 && ! isset($agents[$agentId])) {
                $this->add(
                    "setting.{$key}_foreign_agent",
                    self::SEVERITY_CRITICAL,
                    "Company setting {$key} points to an agent that is not one of this company's agents.",
                    ['setting' => $key, 'agent_id' => $agentId],
                );
            }
        }
    }

    /**
     * A reach-out rule with no agent of its own runs as the agent that has reach-out switched on;
     * with none, every new lead fails the rule. A rule that names its agent (`params.agent_id`)
     * runs as that one whatever the switch says, so it is not part of the finding.
     *
     * @param Collection<int, Rule> $rules
     */
    private function auditReachOut(Collection $rules): void
    {
        $ruleIds = $this->rulesRunning(
            $rules->filter(static fn (Rule $rule): bool => empty($rule->params['agent_id'])),
            AgentReachOutActivity::class
        );

        if ($ruleIds !== [] && Agent::forNewLeads($this->app, $this->company) === null) {
            $this->add(
                'rule.reach_out_without_active_agent',
                self::SEVERITY_CRITICAL,
                'A reach-out rule is live while no active agent has reach-out switched on '
                    . '(reach_out_config.new_leads).',
                ['rule_ids' => $ruleIds],
            );
        }
    }

    private function auditPipelines(): void
    {
        $defaults = Pipeline::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_default', 1)
            ->where('is_deleted', 0)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (count($defaults) > 1) {
            $this->add(
                'pipeline.multiple_defaults',
                self::SEVERITY_MEDIUM,
                'More than one pipeline is marked as default, so which one a new lead lands on is not defined.',
                ['pipeline_ids' => $defaults],
            );
        }
    }

    /**
     * @param list<Agent> $agents
     */
    private function auditCrossTenantPrompts(array $agents): void
    {
        $patterns = $this->otherTenantPatterns();
        if ($patterns === []) {
            return;
        }

        foreach ($agents as $agent) {
            $prompt = implode("\n", [
                (string) $agent->description,
                (string) $agent->soul,
                (string) $agent->instructions,
                (string) json_encode($agent->role),
            ]);

            $hits = [];
            foreach ($patterns as $name => $pattern) {
                if (preg_match($pattern, $prompt) === 1) {
                    $hits[] = $name;
                }
            }

            if ($hits !== []) {
                $this->add(
                    "agent.{$agent->getId()}.cross_tenant_reference",
                    self::SEVERITY_CRITICAL,
                    "Agent {$agent->name} mentions another dealer in its prompt.",
                    ['agent_id' => $agent->getId(), 'other_companies' => $hits],
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function otherTenantPatterns(): array
    {
        $own = mb_strtolower((string) $this->company->name);
        $patterns = [];

        $names = AppsRepository::getActiveCompaniesForAppBuilder($this->app)
            ->where('id', '!=', $this->company->getId())
            ->pluck('name');

        foreach ($names as $name) {
            $name = trim((string) $name);

            if (mb_strlen($name) < self::MIN_TENANT_NAME_LENGTH || str_contains($own, mb_strtolower($name))) {
                continue;
            }

            $patterns[$name] = '/\b' . preg_quote($name, '/') . '\b/iu';
        }

        return $patterns;
    }

    /**
     * @return Collection<int, Rule>
     */
    private function rules(): Collection
    {
        return Rule::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->with('workflowActivities.activity.action')
            ->get();
    }

    /**
     * @param Collection<int, Rule> $rules
     * @param class-string $activity
     * @return list<int>
     */
    private function rulesRunning(Collection $rules, string $activity): array
    {
        return $rules
            ->filter(
                static fn (Rule $rule): bool => $rule->workflowActivities->contains(
                    static fn (RuleAction $step): bool => is_a((string) $step->activity?->actionClass(), $activity, true)
                )
            )
            ->map(static fn (Rule $rule): int => $rule->getId())
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function add(string $code, string $severity, string $summary, array $evidence = []): void
    {
        $this->findings[] = [
            'code' => $code,
            'severity' => $severity,
            'summary' => $summary,
            'evidence' => $evidence,
        ];
    }
}
