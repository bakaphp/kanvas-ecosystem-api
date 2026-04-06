<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

class SetupVoiceWorkflowCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:setup-voice
                            {app_id : App ID}
                            {company_id : Company ID}';

    protected $description = 'Setup required agents and integrations for the voice workflow (LeadAgentFirstMessageOutreachActivity + LeadVoiceFollowUpJob)';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));

        $this->overwriteAppService($app);

        $this->info("Setting up voice workflow for app [{$app->name}] company [{$company->name}]...");

        $agentType = $this->resolveADKAgentType($app);

        $this->setupInternalIntegration($app, $company);
        $mainAgent = $this->setupAgent($app, $company, 'LeadIntentTool', $this->leadIntentToolRole(), $agentType);
        $this->setupAgent($app, $company, 'firstMessageEngagerAgent', $this->firstMessageEngagerRole(), $agentType);
        $this->setupAgent($app, $company, 'voiceOutreachAgent', $this->voiceOutreachAgentRole(), $agentType);

        $this->info('Done. Voice workflow is ready.');
        $this->newLine();
        $this->line('Remaining manual steps:');
        $this->line('  1. Ensure a Workflow Rule exists for Lead "created" event pointing to LeadAgentFirstMessageOutreachActivity');
        $this->line('  2. Set agent_id in Rule params to: ' . ($mainAgent?->getId() ?? '???') . ' (LeadIntentTool)');
        $this->line('  3. Rule params must also include: pipelinesMapping, from (phone number)');
        $this->line('  4. Pipeline stage config must have notification_engagement_rules.templates for sms/email');
        $this->line('  5. VoiceBridge API key must be set on the app: $app->set("voice_bridge_api_key", "...")');
    }

    private function resolveADKAgentType(Apps $app): AgentType
    {
        $agentType = AgentType::fromApp($app)->where('name', 'ADKAgent')->first();

        if ($agentType) {
            return $agentType;
        }

        $agentType = new AgentType();
        $agentType->apps_id = $app->getId();
        $agentType->name = 'ADKAgent';
        $agentType->handler = 'Kanvas\\Intelligence\\Agents\\Types\\ADKAgent';
        $agentType->role = [];
        $agentType->multi_agent_list = [];
        $agentType->is_active = true;
        $agentType->is_published = false;
        $agentType->is_multi_agent = false;
        $agentType->saveOrFail();

        $this->info("  [CREATED] AgentType [ADKAgent] id={$agentType->getId()} for app [{$app->name}]");

        return $agentType;
    }

    private function setupAgent(Apps $app, Companies $company, string $name, array $role, AgentType $agentType): ?Agent
    {
        $agent = Agent::fromApp($app)
            ->fromCompany($company)
            ->where('name', $name)
            ->first();

        if ($agent) {
            if ($agent->agent_type_id !== $agentType->getId()) {
                $agent->agent_type_id = $agentType->getId();
                $agent->saveOrFail();
                $this->info("  [UPDATED] Agent [{$name}] agent_type fixed to ADKAgent (id={$agentType->getId()})");
            } else {
                $this->line("  [OK] Agent [{$name}] already exists with correct agent_type");
            }

            return $agent;
        }

        $userId = $company->users()->value('users_id') ?? 1;

        $agent = new Agent();
        $agent->apps_id = $app->getId();
        $agent->companies_id = $company->getId();
        $agent->agent_type_id = $agentType->getId();
        $agent->user_id = $userId;
        $agent->name = $name;
        $agent->description = $role['description'];
        $agent->config = [];
        $agent->role = $role['role'];
        $agent->is_active = true;
        $agent->saveOrFail();

        $this->info("  [CREATED] Agent [{$name}] id={$agent->getId()} for company [{$company->name}]");

        return $agent;
    }

    private function setupInternalIntegration(Apps $app, Companies $company): void
    {
        $integration = Integrations::where('name', 'internal')->first();

        if (! $integration) {
            $this->warn('  [SKIP] "internal" integration not found in workflow.integrations — seed it first.');
            return;
        }

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        if (! $region) {
            $this->warn("  [SKIP] No region found for company [{$company->name}] — create one first.");
            return;
        }

        $activeStatus = Status::where('slug', 'active')->first();

        $exists = IntegrationsCompany::fromCompany($company)
            ->where('integrations_id', $integration->getId())
            ->where('is_active', 1)
            ->exists();

        if ($exists) {
            $this->line("  [OK] internal integration_company already exists for company [{$company->name}]");
            return;
        }

        IntegrationsCompany::create([
            'companies_id'    => $company->getId(),
            'integrations_id' => $integration->getId(),
            'status_id'       => $activeStatus->getId(),
            'region_id'       => $region->getId(),
            'is_active'       => 1,
        ]);

        $this->info("  [CREATED] internal integration_company for company [{$company->name}]");
    }

    private function leadIntentToolRole(): array
    {
        return [
            'description' => 'Primary lead engagement agent that orchestrates context gathering and first message generation for new leads',
            'role' => [
                'background' => [
                    'You are an expert automotive sales orchestrator. Your goal is to analyze lead information, gather context about their vehicle interest, and coordinate the first engagement message.',
                ],
                'steps' => [
                    'Analyze the lead context including their name, vehicle of interest, source, and any available history.',
                    'Use available tools to gather additional context if needed.',
                    'Coordinate the generation of a personalized first engagement message tailored to the lead\'s specific situation.',
                ],
            ],
        ];
    }

    private function firstMessageEngagerRole(): array
    {
        return [
            'description' => 'Generates the first personalized engagement message for a new lead using AI',
            'role' => [
                'background' => [
                    'You are an expert automotive sales agent. Your goal is to engage leads who have shown interest in a vehicle, introduce yourself professionally, and create interest in scheduling a visit or call.',
                ],
                'steps' => [
                    'Review the lead context provided including their name, vehicle of interest, and any other details available.',
                    'Craft a personalized, friendly, and professional first engagement message. Mention the specific vehicle they are interested in if available. Keep SMS messages under 160 characters. For email, include a clear subject line and a short body.',
                    'Sign off with the sales representative name if available.',
                ],
            ],
        ];
    }

    private function voiceOutreachAgentRole(): array
    {
        return [
            'description' => 'Voice outreach agent that conducts follow-up calls to leads that have not responded to the first message',
            'role' => [
                'background' => [
                    'You are a friendly and professional automotive sales voice agent. Your goal is to call leads who have not responded to an initial outreach message and engage them in a brief, helpful conversation.',
                ],
                'steps' => [
                    'Greet the lead by name and introduce yourself as their sales representative from the dealership.',
                    'Mention that you reached out earlier about their interest in a specific vehicle and wanted to personally follow up.',
                    'Offer to answer any questions, provide more information, or schedule a test drive at their convenience.',
                    'Keep the conversation brief, friendly, and non-pushy. Respect their time.',
                ],
            ],
        ];
    }
}
