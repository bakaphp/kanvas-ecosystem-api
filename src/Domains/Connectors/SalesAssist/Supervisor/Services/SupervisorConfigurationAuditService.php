<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Webhooks\AgentProcessEmailWebhookJob;
use Kanvas\Connectors\Mailgun\Workflows\AgentChannelResponderActivity as MailgunResponderActivity;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Connectors\Twilio\Workflows\AgentChannelResponderActivity as TwilioResponderActivity;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;
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

    /** Shorter names match ordinary words in a prompt ("Magic", "Serra" stay, "BMW" would not). */
    private const int MIN_TENANT_NAME_LENGTH = 5;

    private const array REQUIRED_SETTINGS = [
        'work_hours' => self::SEVERITY_HIGH,
        'working_days' => self::SEVERITY_HIGH,
        'adf_sources' => self::SEVERITY_HIGH,
    ];

    private const array AGENT_SETTINGS = [
        'agent_reach_out_default_agent_id',
        'ai_assist_agent',
    ];

    private const array TWILIO_NUMBER_SETTINGS = [
        'twilio_from_phone_number',
        'twilio_phone_number',
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

        $agents = Agent::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->get();
        $ruleClasses = $this->ruleActionClasses();

        $this->auditCompanyRecord();
        $this->auditRequiredSettings();
        $this->auditAgentSettings($agents->keyBy('id')->all());
        $this->auditReachOut($ruleClasses);
        $this->auditResponders($ruleClasses);
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
                $this->add("setting.{$key}_missing", $severity, "Company setting {$key} is not configured.", [
                    'setting' => $key,
                ]);
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
     * Reach-out resolves its agent without checking is_active, so an inactive First Message still
     * contacts every new lead once the rule exists.
     *
     * @param array<int, list<class-string>> $ruleClasses
     */
    private function auditReachOut(array $ruleClasses): void
    {
        $rules = $this->rulesRunning($ruleClasses, AgentReachOutActivity::class);
        if ($rules === []) {
            return;
        }

        $agentId = (int) $this->company->get('agent_reach_out_default_agent_id');
        $agent = $agentId === 0 ? null : Agent::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->find($agentId);

        if ($agent === null || ! $agent->is_active) {
            $this->add(
                'rule.reach_out_without_active_agent',
                self::SEVERITY_CRITICAL,
                'A reach-out rule is live while the First Message agent is missing or inactive; reach-out does not '
                    . 'check is_active, so new leads can still be contacted.',
                ['rule_ids' => $rules, 'agent_id' => $agentId],
            );
        }
    }

    /**
     * @param array<int, list<class-string>> $ruleClasses
     */
    private function auditResponders(array $ruleClasses): void
    {
        $twilioRules = $this->rulesRunning($ruleClasses, TwilioResponderActivity::class);
        if ($twilioRules !== []) {
            if (array_filter(self::TWILIO_NUMBER_SETTINGS, fn (string $key): bool => empty($this->company->get($key))) !== []) {
                $this->add(
                    'rule.twilio_responder_without_number',
                    self::SEVERITY_HIGH,
                    'A Twilio responder rule exists but the company has no Twilio number configured.',
                    ['rule_ids' => $twilioRules],
                );
            }

            $this->requireReceiver($twilioRules, ProcessTwilioWebhookJob::class, 'rule.twilio_responder_without_receiver');
        }

        $mailgunRules = $this->rulesRunning($ruleClasses, MailgunResponderActivity::class);
        if ($mailgunRules !== []) {
            $this->requireReceiver($mailgunRules, AgentProcessEmailWebhookJob::class, 'rule.mailgun_responder_without_receiver');
        }
    }

    /**
     * @param list<int> $ruleIds
     * @param class-string $jobClass
     */
    private function requireReceiver(array $ruleIds, string $jobClass, string $code): void
    {
        $exists = ReceiverWebhook::query()
            ->forJob($jobClass)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->exists();

        if (! $exists) {
            $this->add(
                $code,
                self::SEVERITY_MEDIUM,
                'A responder rule exists but no active receiver feeds it, so inbound messages never reach it.',
                ['rule_ids' => $ruleIds, 'receiver_job' => class_basename($jobClass)],
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
     * @return array<int, list<class-string>>
     */
    private function ruleActionClasses(): array
    {
        $rules = Rule::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->with('workflowActivities.activity.action')
            ->get();

        $classes = [];
        foreach ($rules as $rule) {
            $classes[$rule->getId()] = $rule->workflowActivities
                ->map(static fn (RuleAction $step): ?string => $step->activity?->actionClass())
                ->filter()
                ->values()
                ->all();
        }

        return $classes;
    }

    /**
     * @param array<int, list<class-string>> $ruleClasses
     * @param class-string $activity
     * @return list<int>
     */
    private function rulesRunning(array $ruleClasses, string $activity): array
    {
        $ids = [];
        foreach ($ruleClasses as $ruleId => $classes) {
            foreach ($classes as $class) {
                if (is_a($class, $activity, true)) {
                    $ids[] = $ruleId;

                    break;
                }
            }
        }

        return $ids;
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
