<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentVersion;
use Kanvas\Users\Models\Users;

/**
 * Change what an agent is TOLD to do, keeping the previous wording so a bad edit is one call to undo.
 *
 * Deliberately narrow: it touches `soul` / `instructions` / `output_format` and nothing else. What an
 * agent can *touch* — its tools, its channels, its tenant — is a separate decision with a separate
 * blast radius, and an instruction edit must never quietly widen it.
 *
 * A hosted agent's remote spec is fingerprinted by `PushAgentDefinitionAction`, so an edit here
 * re-pushes on the agent's next run without anything extra.
 */
class UpdateAgentInstructionsAction
{
    /**
     * @param string|null $instructions null leaves the current value untouched, as do the others
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly Users $editedBy,
        private readonly string $reason,
        private readonly ?string $instructions = null,
        private readonly ?string $soul = null,
        private readonly ?string $outputFormat = null,
    ) {
    }

    public function execute(): AgentVersion
    {
        $changes = array_filter([
            'instructions' => $this->instructions,
            'soul' => $this->soul,
            'output_format' => $this->outputFormat,
        ], fn (?string $value): bool => $value !== null);

        if ($changes === []) {
            throw new ValidationException(
                'Nothing to change — pass at least one of instructions, soul or output_format.'
            );
        }

        if (trim($this->reason) === '') {
            throw new ValidationException(
                'A reason is required so the next person to read the history knows why it changed.'
            );
        }

        return DB::connection($this->agent->getConnectionName())->transaction(function () use ($changes): AgentVersion {
            // Snapshot BEFORE the write: the row records the wording being replaced, so restoring it
            // is a straight copy back.
            $snapshot = $this->snapshot();

            $this->agent->update($changes);

            return $snapshot;
        });
    }

    /**
     * `is_active` marks the most recent snapshot rather than "the agent's current state" — the agent
     * row itself is always the current state. Older snapshots stay for restore.
     */
    private function snapshot(): AgentVersion
    {
        AgentVersion::query()
            ->where('agent_id', $this->agent->getId())
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $previous = (int) AgentVersion::query()
            ->where('agent_id', $this->agent->getId())
            ->count();

        return AgentVersion::create([
            'agent_id' => $this->agent->getId(),
            'version' => (string) ($previous + 1),
            'config' => [
                'instructions' => $this->agent->instructions,
                'soul' => $this->agent->soul,
                'output_format' => $this->agent->output_format,
            ],
            'changes' => $this->reason,
            'created_by' => $this->editedBy->getId(),
            'created_at' => now(),
            'is_active' => true,
        ]);
    }
}
