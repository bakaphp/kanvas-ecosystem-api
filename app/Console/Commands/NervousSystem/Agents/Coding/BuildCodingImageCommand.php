<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Builds the coding image on a machine, from this repository's Dockerfile.
 *
 * The stand-in for a registry. `PrewarmCodingImageAction` pulls, which only works once the tag is
 * published somewhere — until then a new machine has no image and its first job dies during launch.
 * This ships the build context over the connection Kanvas already holds and builds in place.
 *
 * **It does not make the image reproducible.** `apk add` resolves whatever is current at build time, so
 * two machines built a week apart carry different PHP and git builds under one tag — which has already
 * happened here. The digest is recorded per machine so that drift is at least visible; making it
 * impossible needs a registry, and this command is what buys time until there is one.
 */
class BuildCodingImageCommand extends Command
{
    use KanvasJobsTrait;

    /** Where the recorded digest lives, so `kanvas:coding:sessions` and a human can compare machines. */
    public const string DIGEST_FIELD = 'CODING_IMAGE_DIGEST';

    protected $signature = 'kanvas:coding:build-image
        {--machine= : Agent machine id; omit with --all}
        {--app= : App whose configured image tag to build}
        {--image= : Image tag to build, overriding the app setting}
        {--all : Build on every active machine of the app}
        {--no-cache : Build without Docker layer cache}';

    protected $description = 'Build the coding runtime image on a machine, until the image is published to a registry.';

    public function handle(): int
    {
        $app = $this->resolveApp();

        if ($app === null) {
            $this->error('Pass --app with a valid app id.');

            return self::FAILURE;
        }

        $this->overwriteAppService($app);

        $image = Str::trimToNull((string) $this->option('image'))
            ?? Str::trimToNull((string) $app->get(ConfigurationEnum::IMAGE->value));

        if ($image === null) {
            $this->error('No image tag: pass --image, or set ' . ConfigurationEnum::IMAGE->value . ' on the app.');

            return self::FAILURE;
        }

        $machines = $this->machines($app);

        if ($machines === []) {
            $this->error('No machine to build on. Pass --machine, or --all for every active machine.');

            return self::FAILURE;
        }

        $context = $this->buildContext();

        if ($context === null) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($machines as $machine) {
            if (! $this->buildOn($machine, $image, $context)) {
                $failed++;
            }
        }

        $this->reportDrift($machines, $image);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, string> $context filename => contents
     */
    private function buildOn(AgentMachine $machine, string $image, array $context): bool
    {
        $this->line('<info>' . $machine->name . '</info> (' . $machine->host . ')');
        $directory = '/tmp/kanvas-opencode-build-' . $machine->getId();

        try {
            $client = SshClient::fromMachine($machine);
        } catch (Throwable $e) {
            $this->error('  cannot connect: ' . $e->getMessage());

            return false;
        }

        try {
            $client->exec('rm -rf ' . escapeshellarg($directory) . ' && mkdir -p ' . escapeshellarg($directory), 60);

            foreach ($context as $name => $contents) {
                $client->writeFile($directory . '/' . $name, $contents);
            }

            $result = $client->exec(
                'cd ' . escapeshellarg($directory)
                . ' && docker build ' . ($this->option('no-cache') ? '--no-cache ' : '')
                . '-t ' . escapeshellarg($image) . ' . 2>&1 | tail -5; echo "EXIT:${PIPESTATUS[0]}"',
                1800
            );

            if (! str_contains($result, 'EXIT:0')) {
                $this->error('  build failed: ' . trim($result));

                return false;
            }

            $digest = trim($client->exec(
                'docker images --no-trunc --format ' . escapeshellarg('{{.ID}}') . ' ' . escapeshellarg($image),
                60
            ));

            $machine->set(self::DIGEST_FIELD, $digest);
            $this->info('  built ' . $image . ' → ' . mb_substr($digest, 0, 19));

            return true;
        } catch (Throwable $e) {
            $this->error('  ' . $e->getMessage());

            return false;
        } finally {
            // Leaving a build context behind puts our Dockerfile on a customer's disk for no reason.
            try {
                $client->exec('rm -rf ' . escapeshellarg($directory), 60);
            } catch (Throwable $e) {
                report($e);
            }

            $client->disconnect();
        }
    }

    /**
     * Two machines under one tag carrying different images is the whole reason a registry exists. It
     * cannot be prevented here, so it is at least said out loud.
     *
     * @param list<AgentMachine> $machines
     */
    private function reportDrift(array $machines, string $image): void
    {
        $digests = [];

        foreach ($machines as $machine) {
            $digest = Str::trimToNull((string) $machine->get(self::DIGEST_FIELD));

            if ($digest !== null) {
                $digests[$digest][] = $machine->name;
            }
        }

        if (count($digests) <= 1) {
            return;
        }

        $this->newLine();
        $this->warn('Machines are running DIFFERENT images under the tag ' . $image . ':');

        foreach ($digests as $digest => $names) {
            $this->line('  ' . mb_substr((string) $digest, 0, 19) . '  ' . implode(', ', $names));
        }

        $this->line('  Locally built images resolve their packages at build time, so this is expected');
        $this->line('  and will keep happening. Publishing the tag to a registry is what fixes it.');
    }

    /**
     * @return array<string, string>|null
     */
    private function buildContext(): ?array
    {
        $directory = base_path('docker/opencode');
        $context = [];

        foreach (['Dockerfile', 'entrypoint.sh'] as $name) {
            $path = $directory . '/' . $name;

            if (! is_readable($path)) {
                $this->error('Missing build context file: ' . $path);

                return null;
            }

            $context[$name] = (string) file_get_contents($path);
        }

        return $context;
    }

    /**
     * @return list<AgentMachine>
     */
    private function machines(Apps $app): array
    {
        if ($this->option('all')) {
            return AgentMachine::query()
                ->fromApp($app)
                ->notDeleted()
                ->where('is_active', 1)
                ->get()
                ->all();
        }

        $machineId = $this->option('machine');

        if ($machineId === null) {
            return [];
        }

        /** @var AgentMachine|null $machine */
        $machine = AgentMachine::query()->where('id', (int) $machineId)->fromApp($app)->notDeleted()->first();

        return $machine === null ? [] : [$machine];
    }

    private function resolveApp(): ?Apps
    {
        $appId = $this->option('app');

        return $appId === null ? null : Apps::query()->where('id', (int) $appId)->first();
    }
}
