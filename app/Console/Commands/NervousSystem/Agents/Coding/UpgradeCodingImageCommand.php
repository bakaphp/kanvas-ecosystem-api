<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use App\Console\Commands\NervousSystem\Agents\Coding\Concerns\SelectsAppMachines;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Connectors\OpenCode\Actions\ReplaceStaleCodingContainersAction;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingImageService;
use Throwable;

/**
 * Moves an app's coding runtime to the image the Dockerfile now builds: builds it on the machines,
 * points the app at it, and replaces the containers still running the old one.
 *
 * `build-image` alone is not an upgrade. A coding container lives for as long as its agent keeps
 * getting work, and is created with whatever image was configured at the time — so after a build the
 * old opencode keeps serving every agent that already has a container, for days. The reuse check in
 * `EnsureAgentCodingContainerAction` rolls one over on its agent's next task; this does it for the
 * whole machine now, and says which containers it could not touch and why.
 */
class UpgradeCodingImageCommand extends Command
{
    use KanvasJobsTrait;
    use SelectsAppMachines;

    protected $signature = 'kanvas:coding:upgrade-image
        {--app= : App whose machines and image setting move to the new tag}
        {--machine= : Agent machine id; omit with --all}
        {--all : Every active machine of the app}
        {--image= : Image tag to move to; defaults to the tag the Dockerfile builds}
        {--no-build : The image is already on the machines; only replace containers}
        {--force : Also replace containers with a job running or a push awaiting approval, closing those sessions}
        {--dry-run : Report which containers would be replaced without building or touching anything}';

    protected $description = 'Build the coding image on an app\'s machines and replace the containers still running an older one.';

    public function handle(): int
    {
        $app = $this->resolveApp();

        if ($app === null) {
            $this->error('Pass --app with a valid app id.');

            return self::FAILURE;
        }

        $this->overwriteAppService($app);

        // The Dockerfile, not the app setting: the setting is the version being left behind.
        $image = Str::trimToNull((string) $this->option('image')) ?? CodingImageService::pinnedTag();
        $machines = $this->machines($app);

        if ($machines === []) {
            $this->error('No machine to upgrade. Pass --machine, or --all for every active machine.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line('Target image: ' . $image);

        if (! $dryRun && ! $this->option('no-build') && ! $this->build($app->getId(), $image)) {
            $this->error(
                'Containers were left in place: the build failed on at least one machine, and a container'
                . ' removed now would come back on the old image.'
            );

            return self::FAILURE;
        }

        // What dispatch reads. build-image writes it too, but --no-build has to as well, or every
        // replaced container comes back on the tag that was just retired.
        if (! $dryRun && CodingImageService::configure($app, $image)) {
            $this->info('Set ' . ConfigurationEnum::IMAGE->value . ' = ' . $image . ' on app ' . $app->getId() . '.');
        }

        $replaced = 0;
        $kept = 0;
        $closed = 0;
        $failed = 0;

        foreach ($machines as $machine) {
            $this->line('<info>' . $machine->name . '</info> (' . $machine->host . ')');

            try {
                $result = new ReplaceStaleCodingContainersAction(
                    $machine,
                    $image,
                    force: (bool) $this->option('force'),
                    dryRun: $dryRun,
                )->execute();
            } catch (Throwable $e) {
                report($e);
                $this->error('  ' . $e->getMessage());
                $failed++;

                continue;
            }

            foreach ($result['replaced'] as $name) {
                $this->line('  ' . ($dryRun ? 'would replace' : 'replaced') . '  ' . $name);
            }

            foreach ($result['kept'] as $name) {
                $this->line('  kept (in use)  ' . $name);
            }

            if ($result['replaced'] === [] && $result['kept'] === []) {
                $this->line('  every container is on ' . $image);
            }

            $replaced += count($result['replaced']);
            $kept += count($result['kept']);
            $closed += $result['sessions_closed'];
        }

        $this->info(sprintf(
            '%s %d container(s), kept %d in use, closed %d session(s) across %d machine(s).',
            $dryRun ? 'Would replace' : 'Replaced',
            $replaced,
            $kept,
            $closed,
            count($machines),
        ));

        if ($kept > 0) {
            $this->line('A container in use rolls over on its agent\'s next task, or now with --force.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function build(int $appId, string $image): bool
    {
        $options = ['--app' => (string) $appId, '--image' => $image];

        if ($this->option('all')) {
            $options['--all'] = true;
        } else {
            $options['--machine'] = (string) $this->option('machine');
        }

        return $this->call('kanvas:coding:build-image', $options) === self::SUCCESS;
    }
}
