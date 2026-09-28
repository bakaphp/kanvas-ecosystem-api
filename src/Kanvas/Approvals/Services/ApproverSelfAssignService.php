<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

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
    public const string OWNER = 'owner';
    public const string ADMIN = 'admin';

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

    /**
     * Membership is checked before the role: `isAdmin()` reads Bouncer state that is app-scoped, so on
     * its own it would let an admin of one company decide another company's request.
     */
    private function authorityOf(ApprovalRequest $request, Users $user): ?string
    {
        /** @var Companies|null $company */
        $company = $request->company;

        if ($company === null) {
            return null;
        }

        if ($company->isOwner($user)) {
            return self::OWNER;
        }

        // belongsToCompany throws rather than returning false for a non-member, and a stranger has to
        // come back as "no authority" so the action issues the refusal — not as a bare
        // ModelNotFoundException out of an authorization check.
        try {
            if (! UsersRepository::belongsToCompany($user, $company)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $user->isAdmin() ? self::ADMIN : null;
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
