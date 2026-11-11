<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Approvals;

use Kanvas\Approvals\Services\ApprovalCompletionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\Concerns\FindsApprovalRequestForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Retries a failed approved action on its original record to avoid creating a duplicate invoice.
 */
#[AgentTool(name: 'Retry Approved Item', category: 'approvals')]
class RetryApprovedItemTool extends Tool
{
    use FindsApprovalRequestForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'retry_approved_item';

    protected ?string $description = 'Runs the approved action again for a record whose approval went through but '
        . 'whose action failed afterwards (check_approval_status shows action_completed=false with action_error). '
        . 'Fix the cause first (the missing tax id, the range), then call this; nobody is asked to approve again. '
        . 'Never create a new record to replace one whose action failed.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'target_type',
                type: PropertyType::STRING,
                description: 'The kind of record, e.g. "invoice", "credit_note" or "bill".',
                required: true,
            ),
            new ToolProperty(
                name: 'target_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas id of that record.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $target_type, int $target_id): array
    {
        $type = trim($target_type);
        $request = $this->latestApprovalRequest($type, $target_id);

        if ($request === null) {
            return $this->notFound(
                ['target_type' => $type, 'target_id' => $target_id],
                guidance: 'This record has no approval request; check_approval_status tells you its state.'
            );
        }

        try {
            $result = new ApprovalCompletionService()->retry($request, $this->contextUser());
        } catch (ValidationException $e) {
            return $this->denied($e->getMessage(), guidance: 'Nothing was retried; say so.');
        } catch (Throwable $e) {
            report($e);

            return $this->denied("The retry itself failed: {$e->getMessage()}", guidance: 'Nothing changed; say so.');
        }

        $error = $result->handlerError();
        if ($error !== null) {
            return $this->denied(
                "The approved action ran again and failed again: {$error}",
                ['target_type' => $type, 'target_id' => $target_id],
                guidance: 'Fix what the error names, then call this again. Do not create another record.'
            );
        }

        return $this->ok(
            ['target_type' => $type, 'target_id' => $target_id, 'result' => $result->handlerResult],
            'The approved action landed this time. Report what the result says and nothing more.'
        );
    }
}
