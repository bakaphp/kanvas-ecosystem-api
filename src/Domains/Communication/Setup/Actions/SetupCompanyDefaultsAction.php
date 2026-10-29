<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Actions;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Communication\Setup\Enums\SetupSettingEnum;
use Kanvas\Communication\Support\AutoReplyPolicy;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Support\Setup as GuildSetup;
use Kanvas\Intelligence\Agents\Actions\CloneAgentForCompanyAction;
use Kanvas\Intelligence\Agents\Actions\ProvisionAgentUserAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\Actions\ClonePipelineWithFollowUpsAction;

/**
 * What a company needs before a channel can be switched on for it: its own agents, copied from the
 * app's global ones, each with a user of its own; its Sally as the agent that answers inbound; its
 * lead types with their follow-up config; and a copy of the app's follow-up pipeline. Only creates what is missing, so it runs on every setup
 * pass; a company's own agent, lead type config or follow-up is never touched.
 */
final class SetupCompanyDefaultsAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company
    ) {
    }

    /**
     * @return array{agents: list<string>, lead_types_configured: int, follow_up_pipeline: ?int} what was created
     */
    public function execute(): array
    {
        $templates = $this->agentTemplates();
        $followUpPipeline = $this->followUpPipelineTemplate();
        $followsUp = $this->followsUp();

        $agents = [];
        foreach ($templates as $name => $template) {
            new CloneAgentForCompanyAction(
                source: $template,
                app: $this->app,
                company: $this->company,
                owner: $this->company->user,
                user: new ProvisionAgentUserAction($this->app, $this->company, $name)->execute(),
            )->execute();
            $agents[] = $name;
        }

        $this->setupAutoReplyAgent();

        new GuildSetup($this->app, $this->company->user, $this->company)->setupLeadTypes();
        $leadTypesConfigured = $this->setupLeadTypeConfigs();

        // The pipeline comes with the follow-up config, never on its own: a pass that configured no
        // lead type would otherwise copy it again every time setup runs.
        return [
            'agents' => $agents,
            'lead_types_configured' => $leadTypesConfigured,
            'follow_up_pipeline' => $followUpPipeline !== null && ! $followsUp && $leadTypesConfigured > 0
                ? $this->cloneFollowUpPipeline($followUpPipeline)
                : null,
        ];
    }

    /**
     * Here and not with the other switches: the integration's auto-reply rule is made right after
     * this, and only for a company that names the agent answering it.
     */
    private function setupAutoReplyAgent(): void
    {
        if ($this->company->get(AutoReplyPolicy::SETTING) !== null) {
            return;
        }

        $sally = DefaultAgentEnum::SALLY->of($this->app, $this->company);

        if ($sally !== null) {
            $this->company->set(AutoReplyPolicy::SETTING, $sally->getId());
        }
    }

    /**
     * Resolved with the agents, so a setting pointing nowhere blocks setup before anything is created.
     */
    private function followUpPipelineTemplate(): ?Pipeline
    {
        $pipelineId = $this->app->get(SetupSettingEnum::FOLLOW_UP_PIPELINE->value);

        if (empty($pipelineId)) {
            return null;
        }

        return Pipeline::query()
            ->fromApp($this->app)
            ->whereKey((int) $pipelineId)
            ->notDeleted()
            ->first()
            ?? throw new SetupBlockedException(
                'missing_follow_up_pipeline',
                sprintf('%s names pipeline %s, which this app does not have.', SetupSettingEnum::FOLLOW_UP_PIPELINE->value, $pipelineId)
            );
    }

    /**
     * A company follows up once a lead type of its own carries the follow-up config.
     */
    private function followsUp(): bool
    {
        return $this->leadTypes()->contains(fn (LeadType $leadType): bool => ! empty($leadType->config));
    }

    /**
     * @return Collection<int, LeadType>
     */
    private function leadTypes(): Collection
    {
        return LeadType::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->get();
    }

    /**
     * The copy becomes the company's default pipeline: the follow-up only runs on leads in its stages,
     * and new leads land in the default one.
     */
    private function cloneFollowUpPipeline(Pipeline $template): int
    {
        $pipeline = new ClonePipelineWithFollowUpsAction($template, $template->name, $this->company)->execute();
        $pipeline->switchDefaultPipeline();

        return $pipeline->getId();
    }

    /**
     * The global agent to copy for every default agent the company lacks, all resolved before
     * anything is created so a missing one leaves the company as it was.
     *
     * @return array<string, Agent>
     */
    private function agentTemplates(): array
    {
        $templates = [];
        $missing = [];

        foreach (DefaultAgentEnum::cases() as $default) {
            if ($default->of($this->app, $this->company) !== null) {
                continue;
            }

            $template = Agent::query()
                ->fromApp($this->app)
                ->where('companies_id', 0)
                ->where('name', $default->value)
                ->notDeleted()
                ->orderBy('id')
                ->first();

            if ($template === null) {
                $missing[] = $default->value;

                continue;
            }

            $templates[$default->value] = $template;
        }

        if ($missing !== []) {
            throw new SetupBlockedException(
                'missing_agent_template',
                'The app has no global agent to copy for ' . implode(', ', $missing) . '.'
            );
        }

        return $templates;
    }

    private function setupLeadTypeConfigs(): int
    {
        $configured = 0;

        foreach ($this->leadTypes() as $leadType) {
            if (! empty($leadType->config)) {
                continue;
            }

            $setting = strcasecmp($leadType->name, SetupSettingEnum::INTERNET_LEAD_TYPE) === 0
                ? SetupSettingEnum::INTERNET_LEAD_TYPE_CONFIG
                : SetupSettingEnum::OTHER_LEAD_TYPE_CONFIG;
            $config = $this->app->get($setting->value);

            if (! is_array($config) || $config === []) {
                continue;
            }

            $leadType->config = $config;
            $leadType->saveOrFail();
            $configured++;
        }

        return $configured;
    }
}
