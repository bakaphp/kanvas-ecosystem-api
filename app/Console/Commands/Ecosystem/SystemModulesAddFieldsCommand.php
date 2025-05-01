<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\SystemModules\Models\SystemModules;

use function Laravel\Prompts\info;

class SystemModulesAddFieldsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     * Specify as option workflow and receiver for future use.
     *
     * @var string
     */
    protected $signature = 'kanvas:add-system-module-fields {system_module_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Add the fields of a system module';

    public function handle()
    {
        $appId = 0;
        $systemModuleId = $this->argument('system_module_id');
        $systemModule = SystemModules::getById((int) $systemModuleId);

        $this->info("Add the fields of the configuration. Leave the name blank to end.");
        $config = [];

        while (true) {
            // Paso 1.1: Ask for field name
            $fieldName = $this->ask('Enter field name');
            if (empty($fieldName)) {
                break;
            }

            // Paso 1.2: Ask if required
            $isRequired = $this->confirm('¿Is this field required?', true);

            // Add configuration fields
            $config[$fieldName] = [
                'required' => $isRequired
            ];

            $this->info("Field '$fieldName' added.");
        }

        $systemModule->fields = $config;
        $systemModule->saveOrFail();

        info('Fields added successfully - ' . $systemModule->getId() . ' - ' . $systemModule->name);
        $this->line(json_encode($config, JSON_PRETTY_PRINT));
    }
}
