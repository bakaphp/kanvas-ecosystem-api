<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;
use Throwable;

class CreateAgentCommand extends Command
{
    protected $signature = 'agent:create';

    protected $description = 'Wizard to create a new agent record (supports global agents with apps_id=0 / companies_id=0)';

    public function handle(): int
    {
        $this->info('=== Agent Creator ===');
        $this->newLine();

        $appsId = (int) $this->ask('App ID (0 = global / all apps)', 1);

        if ($appsId !== 0) {
            try {
                $app = Apps::getById($appsId);
                $this->line("  App: {$app->name}");
            } catch (Throwable) {
                $this->error("App with ID {$appsId} not found.");

                return Command::FAILURE;
            }
        } else {
            $this->line('  App: <global>');
        }

        $companiesId = (int) $this->ask('Company ID (0 = global / all companies)', 0);

        if ($companiesId !== 0) {
            try {
                $company = Companies::getById($companiesId);
                $this->line("  Company: {$company->name}");
            } catch (Throwable) {
                $this->error("Company with ID {$companiesId} not found.");

                return Command::FAILURE;
            }
        } else {
            $this->line('  Company: <global>');
        }

        $userId = (int) $this->ask('User ID (owner)', 1);

        try {
            Users::getById($userId);
        } catch (Throwable) {
            $this->error("User with ID {$userId} not found.");

            return Command::FAILURE;
        }

        $agentTypeId = (int) $this->ask('Agent Type ID');

        if ($agentTypeId === 0) {
            $this->error('Agent type ID is required.');

            return Command::FAILURE;
        }

        try {
            $agentType = AgentType::findOrFail($agentTypeId);
            $this->line("  Type: {$agentType->name} (handler: {$agentType->handler})");
        } catch (Throwable) {
            $this->error("AgentType with ID {$agentTypeId} not found.");

            return Command::FAILURE;
        }

        $name = $this->ask('Agent name', $agentType->name);

        $agentModelId = $this->ask('Agent Model ID (leave empty to use app default AI config)', '');
        $agentModel = null;

        if ($agentModelId !== '') {
            try {
                $agentModel = AgentModel::findOrFail((int) $agentModelId);
                $this->line("  Model: {$agentModel->name}");
            } catch (Throwable) {
                $this->error("AgentModel with ID {$agentModelId} not found.");

                return Command::FAILURE;
            }
        }

        $instructions = $this->ask('Custom instructions (optional, leave empty to use type defaults)', '');

        $isActive = $this->confirm('Is active?', true);

        $this->newLine();
        $this->info('--- Summary ---');
        $this->line('App:          ' . ($appsId === 0 ? '<global>' : "#{$appsId}"));
        $this->line('Company:      ' . ($companiesId === 0 ? '<global>' : "#{$companiesId}"));
        $this->line("User ID:      {$userId}");
        $this->line("Agent Type:   #{$agentTypeId} ({$agentType->name})");
        $this->line("Name:         {$name}");
        $this->line('Model:        ' . ($agentModel ? $agentModel->name : '<app default>'));
        $this->line('Instructions: ' . ($instructions ?: '<none>'));
        $this->line('Active:       ' . ($isActive ? 'yes' : 'no'));
        $this->newLine();

        if (! $this->confirm('Create this agent?', true)) {
            $this->line('Aborted.');

            return Command::SUCCESS;
        }

        $agent = new Agent();
        $agent->uuid = Str::uuid()->toString();
        $agent->apps_id = $appsId;
        $agent->companies_id = $companiesId;
        $agent->agent_type_id = $agentTypeId;
        $agent->user_id = $userId;
        $agent->name = $name;
        $agent->role = [];
        $agent->instructions = $instructions ?: null;
        $agent->agent_model_id = $agentModel?->getId();
        $agent->is_active = $isActive;
        $agent->saveOrFail();

        $this->newLine();
        $this->info("Agent created with ID: {$agent->getId()}");

        if ($appsId === 0 || $companiesId === 0) {
            $this->line('<fg=yellow>Global agent</> — accessible from any ' . ($appsId === 0 ? 'app' : 'company') . ' via aiAgentChat.');
        }

        $this->newLine();
        $this->line('Use it with:');
        $this->line("  mutation { aiAgentUserChat(input: { agent_id: <fg=yellow>{$agent->getId()}</>, message: \"...\" }) { response session_id } }");

        return Command::SUCCESS;
    }
}
