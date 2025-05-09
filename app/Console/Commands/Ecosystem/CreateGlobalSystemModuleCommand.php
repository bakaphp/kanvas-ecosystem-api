<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\SystemModules\Models\SystemModules;

use function Laravel\Prompts\info;

class CreateGlobalSystemModuleCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     * Specify as option workflow and receiver for future use.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-global-system-module';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Add the fields of a system module';

    public function handle()
    {
        $appId = 0;

        // Paso 1: Ask for integration name
        $class = $this->ask('Enter System Module Class Name. The class must exist');

        if (! class_exists($class)) {
            $this->error('Class does not exist ' . $class);
        }

        $name = $this->ask('Enter System Module Name.');

        $systemModule = SystemModules::firstOrCreate([
            'model_name' => $class,
            'apps_id' => 0,
        ], [
            'model_name' => $class,
            'name' => $name,
            'apps_id' => 0,
            'slug' => Str::simpleSlug($name),
        ]);

        info('System Module created successfully - ' . $systemModule->name . ' - ' . $systemModule->model_name);
    }
}
