<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * Lets a company's owner or an admin decide a request they were not resolved onto — by writing them an
 * approver row first, never by skipping the check. That distinction is the whole design: ApproveAction
 * still reads "who approved" from the rows alone, and the row is stamped with the authority it was
 * taken on, so a decision nobody asked for stays distinguishable from one that was.
 *
 * Opt-in per policy (`allow_authority_override`) and off by default, because on a bill the approver
 * list IS the control and a blanket version would quietly undo that for every adopter.
 */
class ApproverSelfAssignService
{
    public const string OWNER = CompanyAuthorityService::OWNER;
    public const string ADMIN = CompanyAuthorityService::ADMIN;

    /**
     * Best-effort by design: a caller with no authority is simply left alone, so the refusal comes
     * from ApproveAction/RejectAction, which are the single authorization point either way.
     */
    public function ensureCanDecide(ApprovalRequest $request, UserInterface $user): void
    {
        if ($request->policy?->allow_authority_override !== true) {
            return;
        }

        if (! $user instanceof Users || $request->liveApproverRow($user) !== null) {
            return;
        }

        $authority = $this->authorityOf($request, $user);

        if ($authority === null) {
            return;
        }

        $this->assign($request, $user, $authority);
    }

    private function authorityOf(ApprovalRequest $request, Users $user): ?string
    {
        /** @var Companies|null $company */
        $company = $request->company;

        return $company === null ? null : CompanyAuthorityService::authorityOf($company, $user);
    }

    private function assign(ApprovalRequest $request, Users $user, string $authority): void
    {
        $request->grantTurnAtCurrentStep($user);

        $request->metadata = [
            ...($request->metadata ?? []),
            'self_assigned_approvers' => [
                ...(array) ($request->metadata['self_assigned_approvers'] ?? []),
                [
                    'users_id' => $user->getId(),
                    'email' => $user->email,
                    'authority' => $authority,
                    'step' => $request->current_step,
                    'at' => Carbon::now()->toIso8601String(),
                ],
            ],
        ];
        $request->saveOrFail();
    }
}
