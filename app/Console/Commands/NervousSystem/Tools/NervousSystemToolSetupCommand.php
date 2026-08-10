<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Tools;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Throwable;

use function Laravel\Prompts\select;

class NervousSystemToolSetupCommand extends Command
{
    use KanvasJobsTrait;
    protected $signature = 'nervous-system:tool-setup';

    protected $description = 'Wizard to register a tool in the nervous system catalog';

    public function handle(AgentToolDiscoveryService $discovery): int
    {
        $this->info('=== Nervous System Tool Setup ===');
        $this->newLine();

        $isGlobal = $this->confirm('Is this a global tool? (available to all apps, apps_id=0)', false);

        $appsId = 0;

        if (! $isGlobal) {
            $appsId = (int) $this->ask('App ID');

            try {
                $app = Apps::getById($appsId);
                $this->line("App: <fg=green>{$app->name}</>");
                $this->overwriteAppService($app);
            } catch (Throwable) {
                $this->error("App with ID {$appsId} not found.");

                return Command::FAILURE;
            }
        } else {
            $this->line('<fg=yellow>Global tool — will be visible in all apps.</>');
        }

        $this->newLine();

        $name = $this->ask('Tool name');

        if (empty($name)) {
            $this->error('Name is required.');

            return Command::FAILURE;
        }

        $description = $this->ask('Description (optional)', '');

        $frameworkValues = CapabilityFrameworkEnum::values();
        $framework = $this->askForFramework($frameworkValues);

        $toolTypeValues = array_map(fn (ToolTypeEnum $e) => $e->value, ToolTypeEnum::cases());
        $toolType = select(
            label: 'Tool type',
            options: array_combine($toolTypeValues, $toolTypeValues),
            default: ToolTypeEnum::CUSTOM->value,
        );

        $handler = $this->resolveHandler($framework, $discovery);

        $version = $this->ask('Version', '1.0.0');

        $isActive = $this->confirm('Is active?', true);

        $this->newLine();
        $this->info('--- Summary ---');
        $this->line('Scope:       ' . ($isGlobal ? '<fg=yellow>Global (all apps)</>' : "<fg=green>App #{$appsId}</>"));
        $this->line("Name:        {$name}");
        $this->line("Description: {$description}");
        $this->line("Framework:   {$framework}");
        $this->line("Type:        {$toolType}");
        $this->line('Handler:     ' . ($handler ?? '<none>'));
        $this->line("Version:     {$version}");
        $this->line('Active:      ' . ($isActive ? 'yes' : 'no'));
        $this->newLine();

        if (! $this->confirm('Create this tool?', true)) {
            $this->line('Aborted.');

            return Command::SUCCESS;
        }

        $tool = new Tool();
        $tool->apps_id = $appsId;
        $tool->name = $name;
        $tool->description = $description ?: null;
        $tool->tool_type = $toolType;
        $tool->frameworks = [$framework];
        $tool->handler = $handler;
        $tool->version = $version;
        $tool->is_active = $isActive;
        $tool->is_deleted = false;
        $tool->saveOrFail();

        $this->info("Tool created with ID: <fg=green>{$tool->getId()}</>");

        return Command::SUCCESS;
    }

    protected function askForFramework(array $available): string
    {
        return select(
            label: 'Framework (provider)',
            options: array_combine($available, $available),
            default: 'laravel',
        );
    }

    protected function resolveHandler(string $framework, AgentToolDiscoveryService $discovery): ?string
    {
        if (! $this->confirm('Does this tool have a PHP handler class?', true)) {
            return null;
        }

        $discovered = [];
        foreach ($discovery->discover() as $entry) {
            if (in_array($framework, $entry['frameworks'], true)) {
                $discovered[$entry['class']] = $entry['name'] . ' — ' . class_basename($entry['class']);
            }
        }

        if ($discovered !== []) {
            $discovered['manual'] = 'Enter manually';
            $choice = select(
                label: 'Select handler class',
                options: $discovered,
            );

            if ($choice !== 'manual') {
                return $choice;
            }
        }

        return $this->askForHandler();
    }

    protected function askForHandler(): ?string
    {
        $handler = $this->ask('Handler class (fully qualified, e.g. Laravel\\Ai\\Providers\\Tools\\WebSearch)');

        if (empty($handler)) {
            return null;
        }

        if (! class_exists($handler)) {
            $this->warn("Class '{$handler}' not found via autoloader — saving anyway.");
        }

        return $handler;
    }
}
