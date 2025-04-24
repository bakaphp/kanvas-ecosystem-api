<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows\Integrations;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Models\Integrations;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

use function Laravel\Prompts\info;

class CreateIntegrationCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     * Specify as option workflow and receiver for future use.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-integration {name} {--app_id=} {--config=} {--handler=} {--workflow_id=} {--receiver_id=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new Integration';

    /**
     * @psalm-suppress MixedArgument
     *
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     * @throws RuntimeException
     * @throws NonInteractiveValidationException
     */
    public function handle(): void
    {
        $name = $this->argument('name');
        $appId = 0;

        if ($this->option('app_id')) {
            $app = Apps::getById($this->option('app_id'));
            $this->overwriteAppService($app);
            $appId = $app->getId();
        }

        if ($config = $this->option('config')) {
            $config = json_decode($this->option('config'), true);
        }

        $handler = $this->option('handler') ?? null;

        $integration = Integrations::firstOrCreate([
            'name'    => $name,
            'apps_id' => $appId,
        ], [
            'config'  => $config,
            'handler' => $handler,
        ]);

        info('Integration created successfully - '.$integration->getId().' - '.$integration->name);
    }
}
