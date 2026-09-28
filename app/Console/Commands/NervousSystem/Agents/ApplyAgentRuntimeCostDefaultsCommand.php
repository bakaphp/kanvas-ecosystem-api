<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * A container's runtime config is written once at launch, so a cheaper default in code reaches a
 * running agent only through this: it merges the runtime's cost defaults into the live config
 * (keeping admin patches) and restarts the container.
 *
 * What each runtime gets is its own business — OpenClaw takes the model plus heartbeat, compaction
 * and context settings; Hermes has no session knobs, so it takes the model alone.
 */
class ApplyAgentRuntimeCostDefaultsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * A deployment with no `provider` predates the column and is OpenClaw, which is what
     * AgentProviderEnum::forDeployment() falls back to.
     */
    private const array SUPPORTED_PROVIDERS = [
        AgentProviderEnum::OPENCLAW->value,
        AgentProviderEnum::HERMES->value,
    ];

    protected $signature = 'kanvas:agent-runtime-apply-cost-defaults
        {--app= : Restrict to one app id}
        {--deployment= : Restrict to one deployment id}
        {--provider= : Restrict to one runtime (openclaw or hermes)}
        {--dry-run : Print each patch without writing or restarting}';

    protected $description = 'Push the cheap model and session settings to running OpenClaw and Hermes containers';

    public function handle(): int
    {
        $provider = $this->option('provider');

        if ($provider !== null && ! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            $this->error('--provider must be one of: ' . implode(', ', self::SUPPORTED_PROVIDERS));

            return self::FAILURE;
        }

        $deployments = AgentDeployment::query()
            ->where('status', DeploymentStatusEnum::RUNNING->value)
            ->where('is_deleted', 0)
            ->where(fn (Builder $query): Builder => $this->scopeToProvider($query, $provider))
            ->when(
                $this->option('app') !== null,
                fn (Builder $query): Builder => $query->where('apps_id', (int) $this->option('app'))
            )
            ->when(
                $this->option('deployment') !== null,
                fn (Builder $query): Builder => $query->where('id', (int) $this->option('deployment'))
            )
            ->orderBy('id')
            ->get();

        $dryRun = (bool) $this->option('dry-run');

        // Every deployment is an SSH session into a live machine, and a real run restarts each container.
        if (! $this->confirm(sprintf(
            '%s %d running deployment(s) over SSH%s. Continue?',
            $dryRun ? 'Read the config of' : 'Patch and restart',
            $deployments->count(),
            $dryRun ? ' (read-only)' : ''
        ))) {
            return self::SUCCESS;
        }

        $failed = 0;
        $skipped = 0;

        foreach ($deployments->groupBy('apps_id') as $appId => $appDeployments) {
            $this->overwriteAppService(Apps::getById((int) $appId));

            foreach ($appDeployments as $deployment) {
                match ($this->apply($deployment, $dryRun)) {
                    false => $failed++,
                    null => $skipped++,
                    default => null,
                };
            }
        }

        $this->info(sprintf(
            '%d deployment(s) processed, %d already cheap, %d failed.',
            $deployments->count(),
            $skipped,
            $failed
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scopeToProvider(Builder $query, ?string $provider): Builder
    {
        if ($provider === AgentProviderEnum::HERMES->value) {
            return $query->where('provider', $provider);
        }

        if ($provider === AgentProviderEnum::OPENCLAW->value) {
            return $query->where('provider', $provider)->orWhereNull('provider')->orWhere('provider', '');
        }

        return $query->whereIn('provider', self::SUPPORTED_PROVIDERS)
            ->orWhereNull('provider')
            ->orWhere('provider', '');
    }

    /**
     * True applied, null nothing to change, false failed.
     */
    private function apply(AgentDeployment $deployment, bool $dryRun): ?bool
    {
        $label = sprintf('#%d %s', $deployment->getId(), $deployment->container_name);

        try {
            $provider = AgentRuntimeProviderFactory::forDeployment($deployment);
            $current = $provider->fetchConfig($deployment);

            if (trim($current) === '') {
                $this->error($label . ': live config is empty, skipped.');

                return false;
            }

            $patch = $provider->costDefaultsPatch($current);

            if ($patch === '') {
                $this->line($label . ': already on a cheap model, nothing to change.');

                return null;
            }

            if ($dryRun) {
                $this->line($label . "\n" . $patch);

                return true;
            }

            $provider->updateConfig($deployment, $patch);
            $this->info($label . ': applied and restarted.');

            return true;
        } catch (Throwable $e) {
            report($e);
            $this->error($label . ': ' . $e->getMessage());

            return false;
        }
    }
}
