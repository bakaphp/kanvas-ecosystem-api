<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflow;

use App\Console\Commands\KanvasStatusCommand;
use Baka\Contracts\AppInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\DataTransferObject\WedgedWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Services\WedgedWorkflowService;
use Kanvas\Workflow\Support\TransientEntity;
use Throwable;

class RepairWedgedWorkflowsCommand extends Command
{
    protected $signature = 'kanvas:workflow-wedged
        {--fix : Repair what can be repaired; without it this only reports}
        {--attempts= : Attempts a job must have burned to count as wedged}
        {--queue=* : Queues to scan; every Kanvas queue when omitted}';

    protected $description = 'Find workflow jobs that release themselves forever, and optionally unwedge them';

    public function handle(WedgedWorkflowService $service): int
    {
        $queues = $this->option('queue') ?: KanvasStatusCommand::QUEUES;
        $minAttempts = (int) ($this->option('attempts') ?: WedgedWorkflowService::DEFAULT_MIN_ATTEMPTS);

        [$wedged, $others] = $service->scan($queues, $minAttempts);

        if ($wedged === [] && $others === []) {
            $this->info("No job is past {$minAttempts} attempts.");

            return self::SUCCESS;
        }

        if ($others !== []) {
            $this->table(
                [
                    'Job',
                    'Queue',
                    'Jobs',
                    'Most attempts',
                ],
                $others
            );
            $this->warn('Not tied to a stored workflow, so nothing here can repair them.');
        }

        if ($wedged === []) {
            return self::FAILURE;
        }

        $this->table(
            [
                'Workflow',
                'Job',
                'Queue',
                'Attempts',
                'Status',
                'Started',
                'Rule / entity',
                'Last activity',
                'Locks',
                'Repair',
            ],
            array_map($this->row(...), $wedged)
        );

        if (! $this->option('fix')) {
            $this->warn('Report only. Check no activity for these workflows is still running, then re-run with --fix.');

            return self::FAILURE;
        }

        foreach ($wedged as $workflow) {
            $id = $workflow->storedWorkflow->id;

            try {
                $repaired = $service->repair($workflow);
            } catch (Throwable $e) {
                $this->error("  wf={$id} repair failed: " . $e->getMessage());

                continue;
            }

            $this->line($repaired ? "  wf={$id} " . $workflow->cause->repairDescription() : "  wf={$id} left alone");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string|int>
     */
    private function row(WedgedWorkflow $workflow): array
    {
        $storedWorkflow = $workflow->storedWorkflow;
        $lastLog = $storedWorkflow->logs()->orderByDesc('id')->first();

        return [
            $storedWorkflow->id,
            class_basename($workflow->job),
            $workflow->queue,
            $workflow->attempts,
            $storedWorkflow->status::$name,
            (string) $storedWorkflow->created_at,
            $this->target($workflow),
            $lastLog === null ? 'none' : class_basename($lastLog->class) . ' ' . $lastLog->created_at,
            count($workflow->activityLocks),
            $workflow->cause->repairDescription(),
        ];
    }

    private function target(WedgedWorkflow $workflow): string
    {
        try {
            $arguments = $workflow->storedWorkflow->workflowArguments();
        } catch (Throwable $e) {
            // A rule or entity deleted under its workflow is itself why some of these wedge.
            return 'unreadable: ' . class_basename($e);
        }

        $parts = [];

        foreach ($arguments as $argument) {
            $argument = TransientEntity::unwrap($argument);

            if ($argument instanceof Rule) {
                $parts[] = "rule {$argument->getKey()} {$argument->name}";
            } elseif ($argument instanceof Model && ! $argument instanceof AppInterface) {
                $parts[] = class_basename($argument) . '#' . $argument->getKey();
            }
        }

        return implode(' · ', $parts);
    }
}
