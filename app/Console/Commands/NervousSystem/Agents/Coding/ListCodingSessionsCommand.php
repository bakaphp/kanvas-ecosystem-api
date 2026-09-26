<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use Illuminate\Console\Command;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * What coding sessions exist, where their workspaces are, and whether anything is still holding one.
 *
 * The first question when a run misbehaves is "which worktree is this and is it still live", and the
 * answer lived only in the database. `git worktree list` on the machine shows the directories but not
 * which task, agent or tenant they belong to — that mapping is here.
 */
class ListCodingSessionsCommand extends Command
{
    protected $signature = 'kanvas:coding:sessions
        {--live : Only sessions that are still running}
        {--repo= : Restrict to one repository slug}
        {--agent= : Restrict to one agent id}
        {--limit=25 : How many to show}';

    protected $description = 'List coding sessions with their workspaces, branches, status and cost.';

    public function handle(): int
    {
        $query = AgentTaskSession::query()->notDeleted()->orderByDesc('id');

        if ($this->option('live')) {
            $query->live();
        }

        if ($this->option('repo') !== null) {
            $query->where('repo_slug', $this->option('repo'));
        }

        if ($this->option('agent') !== null) {
            $query->where('agent_id', (int) $this->option('agent'));
        }

        $sessions = $query->limit((int) $this->option('limit'))->get();

        if ($sessions->isEmpty()) {
            $this->info('No coding sessions match.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($sessions as $session) {
            $rows[] = [
                $session->task_id,
                $session->agent_id,
                $session->status,
                $session->repo_slug ?? '—',
                $session->branch ?? '—',
                // Attach mode has no workspace of its own: the container owns it and outlives the run.
                $session->workspace_path ?? 'attached',
                $this->age($session),
                '$' . number_format((float) $session->estimated_cost, 4),
            ];
        }

        $this->table(
            ['task', 'agent', 'status', 'repo', 'branch', 'workspace', 'last activity', 'cost'],
            $rows
        );

        $live = $sessions->filter(static fn (AgentTaskSession $s): bool => $s->isLive())->count();
        $this->line($live . ' live of ' . $sessions->count() . ' shown.');

        return self::SUCCESS;
    }

    private function age(AgentTaskSession $session): string
    {
        $seconds = $session->secondsSinceHeartbeat();

        if ($seconds === null) {
            return 'never';
        }

        return $seconds < 90 ? $seconds . 's ago' : (int) round($seconds / 60) . 'm ago';
    }
}
