<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

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
        $updated = [];

        foreach ($discovery->discover() as $entry) {
            /** @var AgentType|null $existing */
            $existing = AgentType::query()
                ->where('handler', $entry['class'])
                ->where('apps_id', 0)
                ->first();

            if ($existing !== null) {
                // Description is the catalog copy an orchestrator reads when routing work to a
                // teammate, so it has to track the code or work goes to the wrong agent. soul,
                // instructions and config are deliberately left alone — those get tuned per
                // deployment and a sync must not stomp on that.
                if ($existing->description !== $entry['description']) {
                    $existing->description = $entry['description'];
                    $existing->saveOrFail();

                    $updated[] = $existing->name;
                }

                continue;
            }

            $agentType = new AgentType([
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
                'handler' => $entry['class'],
            ]);
            // apps_id is guarded (dropped from mass-assignment), and AppsIdTrait defaults it to the
            // ambient app — so set it explicitly to keep the catalog global. (0 ?? appId) === 0.
            $agentType->apps_id = 0;
            $agentType->save();

            $created[] = $agentType->name;
        }

        if ($created !== []) {
            $this->info(count($created) . ' agent type(s) created:');
            foreach ($created as $name) {
                $this->line("- {$name}");
            }
        } else {
            $this->info('No new agent types were created.');
        }

        if ($updated !== []) {
            $this->info(count($updated) . ' description(s) refreshed:');
            foreach ($updated as $name) {
                $this->line("- {$name}");
            }
        }

        $this->info('Syncing Agent Types Done!');

        return self::SUCCESS;
    }
}
