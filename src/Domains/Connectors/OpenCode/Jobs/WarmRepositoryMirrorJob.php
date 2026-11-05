<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\OpenCode\Actions\EnsureRepositoryMirrorAction;
use Kanvas\Connectors\OpenCode\Concerns\PreparesHostDirectory;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesAgentMachine;
use Kanvas\Connectors\OpenCode\Services\CodingWorkspaceService;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Clones a repository's mirror onto the agent's machine when the repository is allowed, so the first
 * task starts from a fetch instead of paying the full clone inside its launch window.
 *
 * Best-effort on purpose: the launch clones anyway if this did not run, and a failure here is a
 * warning in the log rather than anything a human has to act on.
 */
final class WarmRepositoryMirrorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use PreparesHostDirectory;
    use Queueable;
    use ResolvesAgentMachine;
    use SerializesModels;

    public function __construct(
        public readonly Agent $agent,
        public readonly string $slug,
    ) {
        $this->onQueue('coding');
    }

    public function handle(): void
    {
        $app = $this->agent->app;
        $this->overwriteAppService($app);

        $repository = new RepoAllowListService($this->agent)->resolve($this->slug);
        $machine = $this->machineFor($this->agent, $app, $this->agent->company);

        if ($repository === null || $machine === null) {
            return;
        }

        $root = CodingWorkspaceService::rootFor($app);
        $mirror = EnsureRepositoryMirrorAction::pathFor($root, (int) $this->agent->companies_id, $repository->slug);

        try {
            $client = SshClient::fromMachine($machine);
        } catch (Throwable $e) {
            Log::warning('coding.mirror.warm_skipped', [
                'agent' => $this->agent->getId(),
                'slug' => $this->slug,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $this->prepareHostDirectory($client, dirname($mirror, 2), $machine->name);
            new EnsureRepositoryMirrorAction(
                $client,
                $this->agent,
                $mirror,
                $repository->cloneUrl,
                'warm-' . $this->agent->getId() . '-' . $repository->slug
            )->execute();
        } catch (Throwable $e) {
            Log::warning('coding.mirror.warm_failed', [
                'agent' => $this->agent->getId(),
                'slug' => $this->slug,
                'reason' => $e->getMessage(),
            ]);
        } finally {
            $client->disconnect();
        }
    }
}
