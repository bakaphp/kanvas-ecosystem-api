<?php

declare(strict_types=1);

namespace Kanvas\Approvals\DataTransferObject;

use Kanvas\Approvals\Enums\ApprovalOutcomeEnum;
use Kanvas\Approvals\Models\ApprovalRequest;

class ApprovalResult
{
    public function __construct(
        public readonly ApprovalOutcomeEnum $outcome,
        public readonly ApprovalRequest $request,
        public readonly int $have = 0,
        public readonly int $needed = 0,
        public readonly ?int $step = null,
        public readonly ?array $handlerResult = null,
    ) {
    }

    public static function stillPending(ApprovalRequest $request, int $have, int $needed): self
    {
        return new self(
            outcome: ApprovalOutcomeEnum::STILL_PENDING,
            request: $request,
            have: $have,
            needed: $needed,
            step: $request->current_step,
        );
    }

    public static function advanced(ApprovalRequest $request, int $step): self
    {
        return new self(outcome: ApprovalOutcomeEnum::ADVANCED, request: $request, step: $step);
    }

    public static function approved(ApprovalRequest $request, ?array $handlerResult): self
    {
        return new self(
            outcome: ApprovalOutcomeEnum::APPROVED,
            request: $request,
            handlerResult: $handlerResult,
        );
    }

    public static function rejected(ApprovalRequest $request): self
    {
        return new self(outcome: ApprovalOutcomeEnum::REJECTED, request: $request);
    }

    public static function delegated(ApprovalRequest $request): self
    {
        return new self(outcome: ApprovalOutcomeEnum::DELEGATED, request: $request);
    }

    public static function cancelled(ApprovalRequest $request): self
    {
        return new self(outcome: ApprovalOutcomeEnum::CANCELLED, request: $request);
    }

    /**
     * Another caller won the race to close this request. Not an error — the work was done, just not
     * by us, and the handler must not run a second time.
     */
    public static function alreadyResolved(ApprovalRequest $request): self
    {
        return new self(outcome: ApprovalOutcomeEnum::ALREADY_RESOLVED, request: $request);
    }
}
