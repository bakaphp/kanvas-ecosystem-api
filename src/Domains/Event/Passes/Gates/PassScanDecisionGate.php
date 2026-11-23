<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Gates;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;
use Workflow\WorkflowStub;

class PassScanDecisionGate
{
    public const string DECISION_KEY = 'pass_scan_decision';
    public const int UNREADABLE = 0;

    public function __construct(
        protected Apps $app,
        protected UserInterface $scanner,
        protected Products $gateProduct,
    ) {
    }

    public static function decisionFromUse(ParticipantPass $pass): ?int
    {
        $payload = is_string($pass->payload) ? json_decode($pass->payload, true) : $pass->payload;
        $isMultiUse = ($pass->max_uses ?? 1) > 1;

        if (! is_array($payload)) {
            return $isMultiUse && ! in_array($pass->payload, [null, ''], true) ? self::UNREADABLE : null;
        }

        if (! array_key_exists('decision_from_use', $payload)) {
            return null;
        }

        $decisionFromUse = $payload['decision_from_use'];

        if (is_numeric($decisionFromUse) && (int) $decisionFromUse > 0) {
            return (int) $decisionFromUse;
        }

        return $isMultiUse ? self::UNREADABLE : null;
    }

    public function allows(ParticipantPass $pass, EventVersion $booking, int $useNumber): bool
    {
        try {
            $workflows = new ProcessWorkflowEventAction($this->app, $booking)->executeAndCollectSyncWorkflows(
                WorkflowEnum::PASS_SCAN_REQUESTED->value,
                [
                    'use_number' => $useNumber,
                    'participant_passes_id' => $pass->getId(),
                    'products_id' => $this->gateProduct->getId(),
                    'scanner_users_id' => $this->scanner->getId(),
                ]
            );
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return $workflows !== [] && array_all($workflows, $this->workflowAllows(...));
    }

    private function workflowAllows(WorkflowStub $workflow): bool
    {
        try {
            $output = $workflow->completed() ? $workflow->output() : null;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        if (! is_array($output)) {
            return false;
        }

        $decisions = [];

        foreach ($output as $activityResult) {
            if (is_array($activityResult) && array_key_exists(self::DECISION_KEY, $activityResult)) {
                $decisions[] = $activityResult[self::DECISION_KEY];
            }
        }

        return $decisions !== [] && array_all(
            $decisions,
            fn (mixed $decision): bool => is_array($decision) && ($decision['allowed'] ?? null) === true
        );
    }
}
