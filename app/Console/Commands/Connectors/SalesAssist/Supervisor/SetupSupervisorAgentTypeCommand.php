<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\SalesAssist\Supervisor;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Supervisor\Neuron\SupervisorSaAgent;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentType;

/**
 * Creates the type for one app, so it is offered to SalesAssist companies only: AgentType reads its own
 * app plus the global apps_id=0 lane. Safe to run again.
 */
class SetupSupervisorAgentTypeCommand extends Command
{
    protected $signature = 'kanvas:salesassist:setup-supervisor-agent-type {app_id : The SalesAssist app id}';

    protected $description = 'Create the Supervisor SA Agents type for the SalesAssist app.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));

        $type = AgentType::query()->firstOrNew([
            'apps_id' => $app->getId(),
            'handler' => SupervisorSaAgent::class,
        ]);

        $type->apps_id = $app->getId();
        $type->uuid ??= Str::uuid()->toString();
        $type->name = SupervisorSaAgent::TYPE_NAME;
        $type->description = 'Read-only supervisor of a SalesAssist dealer: audits its configuration every hour and reviews its conversations against company policies.';
        $type->provider = 'neuron';
        $type->soul = SupervisorSaAgent::SOUL;
        $type->config ??= [];
        $type->role = json_encode(['role' => SupervisorSaAgent::TYPE_NAME]);
        $type->instructions = 'Review the dealer and record what is wrong.';
        $type->output_format = 'Plain text. One short line per finding recorded.';
        $type->multi_agent_list ??= [];
        $type->is_active = true;
        $type->is_published = true;
        $type->is_deleted = 0;
        $type->save();

        try {
            new CreateRoleAction(
                name: RolesEnums::AGENT_DEVELOPER->value,
                title: 'Agent Developer',
                app: $app,
            )->execute();
        } catch (ValidationException $e) {
            if (! str_contains($e->getMessage(), 'has already been taken')) {
                throw $e;
            }
        }

        $this->info("{$type->name} is type {$type->getId()} on app {$app->getId()}.");

        return self::SUCCESS;
    }
}
