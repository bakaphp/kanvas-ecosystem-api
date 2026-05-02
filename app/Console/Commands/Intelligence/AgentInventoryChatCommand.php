<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Intelligence\Agents\Laravel\Inventory\AgentInventoryAssistance;
use Kanvas\Users\Models\Users;

class AgentInventoryChatCommand extends Command
{
    protected $signature = 'agent:inventory-chat
                            {--app-id=1 : The app ID context}
                            {--user-id=1 : The user ID to chat as}
                            {--company-id= : The company ID context (uses user default if omitted)}';

    protected $description = 'Interactive chat with the Inventory AI agent';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->option('app-id'));
        $user = Users::getById((int) $this->option('user-id'));

        App::scoped(Apps::class, fn () => $app);
        Auth::loginUsingId($user->getId());

        if ($companyId = $this->option('company-id')) {
            $branch = Companies::getById((int) $companyId)->defaultBranch;
            App::scoped(CompaniesBranches::class, fn () => $branch);
        }

        $agent = AgentInventoryAssistance::make()->forUser($user);

        $this->info('Inventory Assistant ready. Type "exit" to quit.');
        $this->newLine();

        while (true) {
            $input = $this->ask('You');

            if (in_array(strtolower(trim($input)), ['exit', 'quit', 'q'])) {
                $this->info('Goodbye!');

                break;
            }

            $response = $agent->prompt($input);

            $this->line('<fg=cyan>Assistant:</> ' . $response->text);
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}
