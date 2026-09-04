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
 * approver row first, never by skipping the check.
 *
 * **Opt-in per policy** (`allow_authority_override`), and off by default, because whether this is
 * correct depends entirely on what the approval is for. On a bill the approver list IS the control:
 * the point of "only these people sign" is that an admin cannot wave one through, and a blanket
 * version of this would quietly undo that for every adopter. On a held agent message draft the list is
 * thin by construction — `channel_members` finds nobody on tens of thousands of channels — so the
 * request lands on whichever single account owns the company and the reviewer looking straight at the
 * card is refused it.
 *
 * Self-assigning rather than bypassing is what keeps the audit answerable. ApproveAction still reads
 * "who approved" from the approver rows alone, and the row written here is stamped into the request's
 * metadata (and its ledger payload) with who took it and on what authority — so a decision nobody was
 * asked for stays distinguishable afterwards from one that was.
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
