<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Kanvas\Intelligence\Agents\Enums\ToolConfirmationResultEnum;
use Kanvas\Intelligence\Agents\Services\ToolConfirmationTokens;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;

trait RequiresTurnConfirmation
{
    use HasTurnContext;
    use ReportsToolOutcome;

    abstract protected function confirmingUserId(): int;

    protected function requireConfirmation(
        string $action,
        array $arguments,
        ?string $confirmationToken,
        string $summary,
    ): ?array {
        if (! $this->hasTurnContext()) {
            return $this->denied(
                'This surface does not identify the conversation turn, so the action cannot be confirmed here.',
                ['executed' => false],
            );
        }

        $tokens = new ToolConfirmationTokens();
        $tool = $this->getName() . ':' . $action;

        if ($confirmationToken !== null && $confirmationToken !== '') {
            $result = $tokens->consume(
                $confirmationToken,
                $this->turnThreadId,
                $this->turnId,
                $this->confirmingUserId(),
                $tool,
                $arguments,
            );

            if ($result === ToolConfirmationResultEnum::CONFIRMED) {
                return null;
            }

            if ($result === ToolConfirmationResultEnum::SAME_TURN) {
                return $this->denied(
                    'The confirmation came in the same turn as the preview. Nothing was done: show the summary, '
                    . 'end your turn and wait for the person to answer in a new message.',
                    ['executed' => false],
                );
            }
        }

        $token = $tokens->issue(
            $this->turnThreadId,
            $this->turnId,
            $this->confirmingUserId(),
            $tool,
            $arguments,
        );

        return [
            'success' => true,
            'executed' => false,
            'status' => 'confirmation_required',
            'summary' => $summary,
            'confirmation_token' => $token,
            'note' => 'NOTHING has been done yet. Show the summary to the person, end your turn and ask them to '
                . 'confirm. Only when they answer yes in a NEW message, call this tool again with the exact same '
                . 'arguments plus confirmation_token. A confirmation in this same turn is rejected.',
        ];
    }
}
