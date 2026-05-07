<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Types\BaseAgent;
use Kanvas\Users\Models\Users;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

class CreateAgentTypeCommand extends Command
{
    protected $signature = 'agent-type:create';

    protected $description = 'Wizard to create a new agent type (and optionally an agent record)';

    public function handle(): int
    {
        $this->info('=== Agent Type Creator ===');
        $this->newLine();

        $appsId = (int) $this->ask('App ID', 1);

        try {
            $app = Apps::getById($appsId);
        } catch (Throwable) {
            $this->error("App with ID {$appsId} not found.");

            return Command::FAILURE;
        }

        $name = $this->ask('Agent type name');

        if (empty($name)) {
            $this->error('Name is required.');

            return Command::FAILURE;
        }

        $description = $this->ask('Description (optional)', '');

        $provider = $this->choice(
            'Provider',
            ['laravel', 'neuron', 'openclaw', 'adk'],
            0
        );

        $handler = $this->resolveHandler($provider);

        if ($handler === null) {
            return Command::FAILURE;
        }

        $role = $this->ask('Role / system instructions (optional)', '');

        $isActive = $this->confirm('Is active?', true);
        $isPublished = $this->confirm('Is published?', true);

        $this->newLine();
        $this->info('--- Summary ---');
        $this->line("App:         #{$appsId} ({$app->name})");
        $this->line("Name:        {$name}");
        $this->line("Provider:    {$provider}");
        $this->line("Handler:     {$handler}");
        $this->line("Description: {$description}");
        $this->line('Active:      ' . ($isActive ? 'yes' : 'no'));
        $this->line('Published:   ' . ($isPublished ? 'yes' : 'no'));
        $this->newLine();

        if (! $this->confirm('Create this agent type?', true)) {
            $this->line('Aborted.');

            return Command::SUCCESS;
        }

        $agentType = new AgentType();
        $agentType->uuid = Str::uuid()->toString();
        $agentType->apps_id = $appsId;
        $agentType->name = $name;
        $agentType->description = $description ?: null;
        $agentType->provider = $provider;
        $agentType->handler = $handler;
        $agentType->role = $role ?: '';
        $agentType->is_active = $isActive;
        $agentType->is_published = $isPublished;
        $agentType->is_multi_agent = false;
        $agentType->multi_agent_list = [];
        $agentType->saveOrFail();

        $this->info("Agent type created with ID: {$agentType->getId()}");

        if (! $this->confirm('Do you want to create an agent record linked to this type?', true)) {
            return Command::SUCCESS;
        }

        return $this->createAgent($agentType, $app);
    }

    protected function resolveHandler(string $provider): ?string
    {
        $discovered = $this->discoverHandlers($provider);

        if (! empty($discovered)) {
            $discovered[] = 'Enter manually';
            $choice = $this->choice('Select handler class', $discovered, 0);

            if ($choice === 'Enter manually') {
                return $this->askForHandler();
            }

            return $choice;
        }

        return $this->askForHandler();
    }

    protected function askForHandler(): ?string
    {
        $handler = $this->ask('Handler class (fully qualified)');

        if (empty($handler)) {
            $this->error('Handler is required.');

            return null;
        }

        if (! class_exists($handler)) {
            $this->warn("Class '{$handler}' not found via autoloader — saving anyway.");
        }

        return $handler;
    }

    protected function discoverHandlers(string $provider): array
    {
        $paths = match ($provider) {
            'laravel' => [base_path('src/Domains/Intelligence/Agents/Laravel')],
            'neuron' => [base_path('src/Domains/Intelligence/Agents/Types')],
            default => [],
        };

        $classes = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $finder = (new Finder())->files()->name('*.php')->in($path);

            foreach ($finder as $file) {
                $class = $this->fileToClass($file->getRealPath());

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                $ref = new ReflectionClass($class);

                if ($ref->isAbstract() || $ref->isInterface()) {
                    continue;
                }

                $isLaravel = $ref->isSubclassOf(KanvasLaravelAgent::class);
                $isNeuron = $ref->isSubclassOf(BaseAgent::class);

                if ($provider === 'laravel' && $isLaravel) {
                    $classes[] = $class;
                } elseif ($provider === 'neuron' && $isNeuron) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    protected function fileToClass(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^(?:abstract\s+)?class\s+(\w+)/m', $content, $classMatch)) {
            return null;
        }

        return $nsMatch[1] . '\\' . $classMatch[1];
    }

    protected function createAgent(AgentType $agentType, Apps $app): int
    {
        $this->newLine();
        $this->info('=== Create Agent ===');

        $agentName = $this->ask('Agent name', $agentType->name);

        $companyId = (int) $this->ask('Company ID', 1);

        try {
            $company = Companies::getById($companyId);
        } catch (Throwable) {
            $this->error("Company with ID {$companyId} not found.");

            return Command::FAILURE;
        }

        $userId = (int) $this->ask('User ID (owner)', 1);

        try {
            Users::getById($userId);
        } catch (Throwable) {
            $this->error("User with ID {$userId} not found.");

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('--- Agent Summary ---');
        $this->line("Name:        {$agentName}");
        $this->line("Agent Type:  #{$agentType->getId()} ({$agentType->name})");
        $this->line("Company:     #{$companyId} ({$company->name})");
        $this->line("User ID:     {$userId}");
        $this->newLine();

        if (! $this->confirm('Create this agent?', true)) {
            $this->line('Aborted.');

            return Command::SUCCESS;
        }

        $agent = new Agent();
        $agent->uuid = Str::uuid()->toString();
        $agent->apps_id = $app->getId();
        $agent->companies_id = $companyId;
        $agent->agent_type_id = $agentType->getId();
        $agent->user_id = $userId;
        $agent->name = $agentName;
        $agent->role = [];
        $agent->is_active = true;
        $agent->saveOrFail();

        $this->info("Agent created with ID: {$agent->getId()}");
        $this->newLine();
        $this->line('You can now use <fg=green>aiAgentChat</> with:');
        $this->line("  agent_id:   <fg=yellow>{$agent->getId()}</>");
        $this->line("  session_id: <fg=yellow>\"\"</> (or create one via aiAgentUserChat)");

        return Command::SUCCESS;
    }
}
