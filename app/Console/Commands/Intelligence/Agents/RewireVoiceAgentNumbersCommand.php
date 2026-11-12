<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Actions\Phone\ConfigureAgentInboundWebhookAction;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;

/**
 * Points every voice agent's number at this environment's voice runtime. Run it after
 * VOICE_RUNTIME_URL changes: until an agent is saved again its number keeps calling the
 * old runtime, which doesn't know the agent and answers as the default one. Numbers on
 * another system's webhook are left alone.
 */
class RewireVoiceAgentNumbersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:voice-agents-rewire-numbers
        {--app= : Only agents of this app id}
        {--dry-run : List the numbers and the target webhook, writing nothing}';

    protected $description = "Point voice agents' numbers at this environment's voice runtime";

    public function handle(): int
    {
        $target = VoiceRuntimeConfig::inboundWebhookUrl();
        if ($target === '') {
            $this->error('VOICE_RUNTIME_URL is not set.');

            return self::FAILURE;
        }

        $this->line("Target webhook: {$target}");
        $failed = 0;

        Agent::query()
            ->notDeleted()
            ->whereNotNull('voice_config')
            ->when($this->option('app'), fn ($query, $app) => $query->where('apps_id', (int) $app))
            ->orderBy('id')
            ->each(function (Agent $agent) use (&$failed): void {
                $number = $agent->phoneNumber(AgentPhoneChannelEnum::VOICE);
                if ($number === null) {
                    return;
                }

                $label = "Agent {$agent->getId()} ({$agent->name}), app {$agent->apps_id}: {$number}";
                if ($this->option('dry-run')) {
                    $this->line($label);

                    return;
                }

                $this->overwriteAppService($agent->app);
                $result = $this->configure($agent);
                $this->line("{$label} -> {$result['status']} ({$result['message']})");
                foreach ($result['warnings'] as $warning) {
                    $this->warn("  {$warning}");
                }
                if (in_array($result['status'], ['error', 'conflict'], true)) {
                    $failed++;
                }
            });

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     */
    protected function configure(Agent $agent): array
    {
        return new ConfigureAgentInboundWebhookAction($agent)->execute();
    }
}
