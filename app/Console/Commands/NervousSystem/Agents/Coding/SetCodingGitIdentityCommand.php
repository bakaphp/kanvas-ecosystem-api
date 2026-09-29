<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Connectors\OpenCode\Actions\SetAgentGitIdentityAction;
use Kanvas\Connectors\OpenCode\DataTransferObject\GitIdentity;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Sets the name and email a coding agent's commits are authored under — any email, which is why this is
 * an admin command. The agent's own `set_coding_commit_identity` tool can only pick the person talking to it.
 *
 * The host's git config plays no part: the push passes the identity on every commit, so
 * `git config --global` on the runner changes nothing.
 */
class SetCodingGitIdentityCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:coding:git-identity
        {--agent= : Agent id}
        {--email= : Commit author email, e.g. one the deploy platform maps to a team member}
        {--name= : Commit author name; the agent\'s own name when omitted}
        {--reset : Go back to the agent\'s name and ' . GitIdentity::DEFAULT_EMAIL . '}';

    protected $description = 'Set the commit author identity a coding agent pushes with.';

    public function handle(): int
    {
        /** @var Agent|null $agent */
        $agent = Agent::query()->where('id', (int) $this->option('agent'))->first();

        if ($agent === null) {
            $this->error('Pass --agent with a valid agent id.');

            return self::FAILURE;
        }

        $this->overwriteAppService($agent->app);

        $action = new SetAgentGitIdentityAction($agent);

        try {
            $identity = $this->option('reset')
                ? $action->reset()
                : $action->set(name: $this->option('name'), email: $this->option('email'));
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($agent->name . ' commits as: ' . $identity);

        if ($identity->isDefaultEmail()) {
            $this->line('  Deploy platforms that verify commit authors (Vercel, Netlify) will not recognise this '
                . 'email. Pass --email with one a team member owns.');
        }

        return self::SUCCESS;
    }
}
