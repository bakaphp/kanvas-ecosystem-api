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
            // AgentObserver::updating writes the snapshot; this only supplies the reason and editor.
            $this->agent->versionChangeReason = $this->reason;
            $this->agent->versionEditedByUserId = $this->editedBy->getId();

            $this->agent->update($changes);

            $version = $this->agent->lastRecordedVersion;

            if ($version === null) {
                throw new ValidationException(
                    'The instructions were saved but the previous wording could not be recorded, so '
                    . 'this edit cannot be undone. Tell an administrator before changing it again.'
                );
            }

            return $version;
        });
    }
}
