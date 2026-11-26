<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Actions;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Communication\Setup\Enums\SetupSettingEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Support\Setup as GuildSetup;
use Kanvas\Intelligence\Agents\Actions\CloneAgentForCompanyAction;
use Kanvas\Intelligence\Agents\Actions\ProvisionAgentUserAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\Actions\ClonePipelineWithFollowUpsAction;

/**
 * What a company needs before a channel can be switched on for it: its own agents, each copied from
 * the app's onboarding template of that name (an `agents` row with `companies_id = 0`, which never
 * acts for a company itself) and given a user of its own; its lead types with their follow-up
 * config; and a copy of the app's follow-up pipeline. Only creates what is missing, so it runs on
 * every setup pass; a company's own agent, lead type config or follow-up is never touched.
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
        // Every copy is owned by the company's owner, so without one nothing may be created at all.
        if ($this->company->user === null) {
            throw new SetupBlockedException(
                'missing_company_owner',
                'Company ' . $this->company->getId() . ' has no owner to own its agents; set one and the setup runs again.'
            );
        }

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

        new GuildSetup($this->app, $this->company->user, $this->company)->setupLeadTypes();
        $leadTypes = $this->leadTypesToConfigure();

        // The pipeline comes with the follow-up config, never on its own: a pass that configures no
        // lead type would otherwise copy it again every time setup runs. And before the config is
        // saved: a saved config is what says the company follows up, so a copy that failed after it
        // would never be tried again.
        $pipelineId = $followUpPipeline !== null && ! $followsUp && $leadTypes !== []
            ? $this->cloneFollowUpPipeline($followUpPipeline)
            : null;

        foreach ($leadTypes as $leadType) {
            $leadType->saveOrFail();
        }

        return [
            'agents' => $agents,
            'lead_types_configured' => count($leadTypes),
            'follow_up_pipeline' => $pipelineId,
        ];
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
            ->fromCompany($this->company->getId())
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
     * The template to copy for every default agent the company lacks, all resolved before anything
     * is created so a missing one leaves the company as it was.
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
                'The app has no agent template to copy for ' . implode(', ', $missing) . '.'
            );
        }

        return $templates;
    }

    /**
     * @return list<LeadType> the ones still without a config, each carrying the app's, not saved yet
     */
    private function leadTypesToConfigure(): array
    {
        $toConfigure = [];

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
            $toConfigure[] = $leadType;
        }

        return $toConfigure;
    }
}
