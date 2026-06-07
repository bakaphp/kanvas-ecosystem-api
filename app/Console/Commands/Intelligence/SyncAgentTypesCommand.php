<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Services\AgentTypeDiscoveryService;

class SyncAgentTypesCommand extends Command
{
    protected $signature = 'kanvas:intelligence:sync-agent-types';

    protected $description = 'Discover #[AgentTypeDefinition] handler classes and sync them into the agent_types catalog (global, apps_id=0).';

    public function handle(AgentTypeDiscoveryService $discovery): int
    {
        $this->info('Syncing Agent Types...');

        $created = [];

        foreach ($discovery->discover() as $entry) {
            $agentType = AgentType::firstOrCreate(
                [
                    'handler' => $entry['class'],
                    'apps_id' => 0,
                ],
                [
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'provider' => $entry['provider'],
                    'soul' => $entry['soul'],
                    'instructions' => $entry['instructions'],
                    'output_format' => $entry['outputFormat'],
                    'role' => $entry['role'] ?? json_encode([]),
                    'config' => $entry['config'],
                    'multi_agent_list' => [],
                    'is_active' => true,
                    'is_published' => $entry['isPublished'],
                    'is_default' => $entry['isDefault'],
                    'is_multi_agent' => $entry['isMultiAgent'],
                    'weight' => $entry['weight'],
                    'is_deleted' => false,
                ],
            );

            if ($agentType->wasRecentlyCreated) {
                $created[] = $agentType->name;
            }
        }

        if ($created !== []) {
            $this->info(count($created) . ' agent type(s) created:');
            foreach ($created as $name) {
                $this->line("- {$name}");
            }
        } else {
            $this->info('No new agent types were created.');
        }

        $this->info('Syncing Agent Types Done!');

        return self::SUCCESS;
    }
}
