<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows\Integrations;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\Integrations;

use function Laravel\Prompts\info;

class CreateIntegrationSetupCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     * Specify as option workflow and receiver for future use.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-integration-setup {--app_id=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new Integration';

    public function handle()
    {
        $appId = 0;

        // Paso 1: Ask for integration name
        $name = $this->ask('Enter Integration Name');

        $handler = $this->ask('Enter the integration handler class name');

        if ($this->option('app_id')) {
            $app = Apps::getById($this->option('app_id'));
            $this->overwriteAppService($app);
            $appId = $app->getId();
        }

        // Paso 2: Ask if have configuration
        $hasConfig = $this->confirm('¿Does the integration have config?', false);

        $config = [];

        if ($hasConfig) {
            $this->info("Add the fields of the configuration. Leave the name blank to end.");

            while (true) {
                // Paso 3.1: Ask for field name
                $fieldName = $this->ask('Enter configuration name');
                if (empty($fieldName)) {
                    break;
                }

                // Paso 3.2: Ask for data type
                $fieldType = $this->choice('Select data type', ['text', 'number', 'boolean', 'json'], 0);

                // Paso 3.3: Ask if required
                $isRequired = $this->confirm('¿Is this field required?', true);

                // Add configuration fields
                $config[$fieldName] = [
                    'type' => $fieldType,
                    'required' => $isRequired
                ];

                $this->info("Field '$fieldName' added.");
            }
        }

        $integrationData = [
            'name' => $name,
            'config' => $config,
            'handler' => $handler
        ];

        $integration = Integrations::firstOrCreate([
            'name' => $name,
            'apps_id' => $appId,
        ], [
            'config' => $config,
            'handler' => $handler,
        ]);

        info('Integration created successfully - ' . $integration->getId() . ' - ' . $integration->name);
        $this->line(json_encode($integrationData, JSON_PRETTY_PRINT));
    }
}
