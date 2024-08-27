<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows\Integrations;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Models\Integrations;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;

use function Laravel\Prompts\info;

use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class CreateIntegrationWorkflowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-integration {name} {app_id} {--config=}';

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
        $app = Apps::getById($this->argument('app_id'));
        $name = $this->argument('name');
        if($config = $this->option('config')) {
            $config = json_decode($this->option('config'), true);
        }

        $integration = Integrations::firstOrCreate([
            'name' => $name,
            'apps_id' => $app->getId(),
        ], [
            'config' => $config
        ]);

        info('Integration created successfully - ' . $integration->getId() . ' - ' . $integration->name);
    }
}
