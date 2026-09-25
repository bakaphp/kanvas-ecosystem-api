<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\AbsorbHarnessTickAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\DispatchHarnessTaskAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Drives one coding task end to end in the foreground, printing what the agent does as it does it.
 *
 * It exists because the real path is asynchronous — dispatch returns immediately and a queued poller
 * does the rest — which is correct in production and useless when you are trying to see whether any of
 * this works. Here the polling happens inline so a single command shows the whole run.
 */
class CodingSmokeTestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:coding:smoke-test
        {--agent= : Agent id or uuid to run as}
        {--task= : What the coding agent should do}
        {--repo= : Repository slug from the agent\'s allow-list (scopes its memory)}
        {--endpoint= : An already-running opencode server, e.g. http://127.0.0.1:4096}
        {--password= : That server\'s OPENCODE_SERVER_PASSWORD}
        {--provider= : Provider id as declared in the runtime config, e.g. oai}
        {--model= : Model id, e.g. gpt-4.1}
        {--ticks=20 : How many times to poll before giving up}
        {--sleep=10 : Seconds between polls}';

    protected $description = 'Run one coding task through the harness in the foreground and print the result.';

    public function handle(): int
    {
        $agent = $this->resolveAgent();

        if ($agent === null) {
            $this->error('Pass --agent with an existing agent id or uuid.');

            return self::FAILURE;
        }

        $task = (string) $this->option('task');

        if (trim($task) === '') {
            $this->error('Pass --task with something for the agent to do.');

            return self::FAILURE;
        }

        /** @var Apps $app */
        $app = $agent->app;
        $this->overwriteAppService($app);
        $this->applyOverrides($app);

        $this->line('Dispatching as agent <info>' . $agent->name . '</info> (app ' . $app->getId() . ')');

        try {
            $taskRecord = new DispatchHarnessTaskAction(
                agent: $agent,
                task: $task,
                repoSlug: Str::trimToNull((string) $this->option('repo')),
            )->execute();
        } catch (Throwable $e) {
            $this->error('Dispatch failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->forTask($taskRecord->getId())->latest('id')->first();

        if ($session === null) {
            $this->error('No session row was created.');

            return self::FAILURE;
        }

        $this->line('Plan #' . $taskRecord->plan_id . ' · task #' . $taskRecord->getId()
            . ' · session ' . $session->uuid);
        $this->line('Endpoint: ' . (string) $session->endpoint);
        $this->newLine();

        return $this->follow($session);
    }

    private function follow(AgentTaskSession $session): int
    {
        $maxTicks = (int) $this->option('ticks');
        $sleep = (int) $this->option('sleep');

        for ($tickNumber = 1; $tickNumber <= $maxTicks; $tickNumber++) {
            sleep($sleep);

            $session->refresh();

            try {
                $tick = HarnessFactory::forSession($session)->poll($session);
            } catch (Throwable $e) {
                $this->warn('poll failed: ' . $e->getMessage());

                continue;
            }

            foreach ($tick->narration as $line) {
                $this->line('<comment>🔧</comment> ' . mb_substr($line, 0, 400));
            }

            foreach ($tick->permissions as $permission) {
                $this->warn('🔐 waiting on permission: ' . $permission->describe());
            }

            // Same write path as the queued poller — a second copy here is how the command ended up
            // reporting every session as free.
            new AbsorbHarnessTickAction($session, $tick, announce: false)->execute();

            $this->line(sprintf(
                '  [%d/%d] %s · %s in / %s out · models: %s',
                $tickNumber,
                $maxTicks,
                $tick->status->value,
                number_format($tick->usage->inputTokens),
                number_format($tick->usage->outputTokens),
                implode(',', $tick->modelsObserved) ?: '—'
            ));

            if ($tick->status === HarnessStatusEnum::IDLE || $tick->status->isTerminal()) {
                $finalized = new FinalizeHarnessSessionAction($session, $tick)->execute();

                $this->newLine();
                $this->info('Finished: ' . $finalized->status);
                $this->line('Cost: $' . $finalized->estimated_cost);

                if ($finalized->handoff !== null) {
                    $this->newLine();
                    $this->line('<info>Handoff</info>');
                    $this->line($finalized->handoff);
                }

                return self::SUCCESS;
            }
        }

        // Leaving it live would hold a concurrency slot until the sweeper's stale window expires, which
        // in practice means the next smoke test is refused.
        $this->warn('Still running after ' . $maxTicks . ' polls — closing it out as cancelled.');
        $session->status = HarnessStatusEnum::CANCELLED->value;
        $session->error_message = 'The foreground smoke test stopped watching before it finished.';
        $session->saveOrFail();
        new FinalizeHarnessSessionAction($session, null, (string) $session->error_message)->execute();

        return self::FAILURE;
    }

    private function applyOverrides(Apps $app): void
    {
        $overrides = [
            ConfigurationEnum::STATIC_ENDPOINT->value => $this->option('endpoint'),
            ConfigurationEnum::STATIC_PASSWORD->value => $this->option('password'),
            ConfigurationEnum::PROVIDER_ID->value => $this->option('provider'),
            ConfigurationEnum::MODEL->value => $this->option('model'),
        ];

        foreach ($overrides as $key => $value) {
            if ($value !== null && $value !== '') {
                $app->set($key, $value);
            }
        }
    }

    private function resolveAgent(): ?Agent
    {
        $reference = (string) $this->option('agent');

        if (trim($reference) === '') {
            return null;
        }

        return Agent::query()
            ->where(is_numeric($reference) ? 'id' : 'uuid', $reference)
            ->notDeleted()
            ->first();
    }
}
