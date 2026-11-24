<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Kanvas\Guild\Leads\Summary\Output\CommitmentOperation;
use Kanvas\Guild\Leads\Summary\Output\LeadSummaryUpdate;

/**
 * What Neuron cannot check: that each operation means something on this lead. Neuron proves the shape;
 * this proves every item id is a live item of this summary and every citation is a message of this
 * batch, which is this lead's and counted by construction. Neither proves the change is right; that is
 * the evaluation suite's job.
 */
final class LeadSummaryUpdateValidator
{
    public function __construct(
        private readonly LeadSummaryState $state,
        private readonly LeadSummaryBatch $batch,
    ) {
    }

    /**
     * @return list<string> one line per problem, worded for the model's retry; empty when it can be applied
     */
    public function violations(LeadSummaryUpdate $update): array
    {
        $violations = [];
        $touched = [];

        foreach ($update->operations as $position => $operation) {
            $label = sprintf('operation %d (%s)', $position + 1, $operation->op);

            foreach ($operation->citations as $citation) {
                if (! $this->batch->has($citation->message_id)) {
                    $violations[] = "{$label} cites message {$citation->message_id}, which is not in this batch; cite only the ids shown";
                }
            }

            if ($operation->op === CommitmentOperation::ADD) {
                if (! in_array($operation->kind, ['commitment', 'instruction'], true)) {
                    $violations[] = "{$label} needs kind commitment or instruction";
                }

                if (trim((string) $operation->text) === '') {
                    $violations[] = "{$label} needs text";
                }

                continue;
            }

            $item = $operation->item_id === null ? null : $this->state->findItem($operation->item_id);

            if ($item === null || $item['status'] !== LeadSummaryState::OPEN) {
                $violations[] = "{$label} names item_id " . ($operation->item_id ?? 'null') . ', which is not an open item';

                continue;
            }

            if (isset($touched[$item['id']])) {
                $violations[] = "{$label} changes item {$item['id']} a second time";
            }

            $touched[$item['id']] = true;

            if ($operation->op === CommitmentOperation::REPLACE && trim((string) $operation->text) === '') {
                $violations[] = "{$label} needs the corrected text";
            }
        }

        return $violations;
    }
}
