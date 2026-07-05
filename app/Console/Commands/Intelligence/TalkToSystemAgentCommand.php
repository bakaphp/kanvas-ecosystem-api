<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\System\ConverseWithSystemAgentAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

class TalkToSystemAgentCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:agent-talk {app_id} {agent_id} {user_id} {message}';
    protected $description = 'Send one message from a user to a system agent and print the reply';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $user = Users::getById((int) $this->argument('user_id'));
        $company = $user->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $this->argument('agent_id'), $company, $app);

        $reply = new ConverseWithSystemAgentAction(
            agent: $agent,
            human: $user,
            message: (string) $this->argument('message'),
        )->execute();

        $this->info($reply);

        return self::SUCCESS;
    }
}
