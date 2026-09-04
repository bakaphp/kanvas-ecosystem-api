<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Approvals;

use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Repositories\ApprovalPolicyRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * The default policy behind every held agent draft.
 *
 * Auto-provisioned rather than seed-only, which is the opposite of how every other approvable model
 * works — and deliberately so. For a bill, no policy means no gate, and an ungated bill is the normal
 * case. A message is already locked by the time we get here: no policy would mean a draft nobody can
 * ever approve and nothing can ever send. An opinionated default beats a dead card.
 *
 * The default is as close to what the message flow did before it moved onto the approvals domain —
 * anyone looking at the channel could approve — as approver rows allow. A tenant tightens it by
 * editing the row; nothing here overwrites an existing policy.
 */
final class MessageApprovalPolicyService
{
    public static function ensureFor(Message $message): ApprovalPolicy
    {
        $existing = ApprovalPolicyRepository::findByType($message, MessageApproval::APPROVAL_TYPE);

        return $existing ?? self::create($message->app, $message->company);
    }

    public static function create(Apps $app, Companies $company): ApprovalPolicy
    {
        $systemModule = SystemModulesRepository::getByModelName(Message::class, $app);

        return ApprovalPolicy::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'system_modules_id' => $systemModule->getId(),
                'approval_type' => MessageApproval::APPROVAL_TYPE,
            ],
            [
                // The people in the channel the draft was posted to — one step, whatever the kind,
                // because "who can see this" is the same question for all of them. Routing held
                // outbound to the company owner instead reads plausible and is wrong in practice: on
                // a dealership tenant the owner is an account nobody signs into, while the channel
                // member is the person actually reviewing the reply.
                'steps' => [[
                    'step' => 1,
                    'resolver' => 'channel_members',
                    'config' => [],
                    'required_approvals' => 1,
                ]],
                'handler' => AgentMessageApprovalHandler::class,
                'trigger' => ApprovalTriggerEnum::MANUAL,
                'reject_policy' => 'any',
                // A held draft whose request resolved no approvers is worse than one with broad
                // approvers: the send is already blocked, so an unassigned request is work stuck with
                // nothing able to release it.
                'fallback_resolver' => 'company_owner',
                'fallback_config' => [],
                // Reviewing an outbound reply is review, not a financial control, and the resolvers
                // above are thin enough that the person looking at the card is often not on the list.
                // An owner or admin may take it; the approvals domain records that they did.
                'allow_authority_override' => true,
                // The card in the feed IS the notification, and held agent replies are the
                // highest-volume approvable thing on the platform by an order of magnitude — mailing
                // every channel member per draft is a different feature, not a default.
                'notify' => 'none',
                'expires_after_hours' => null,
            ]
        );
    }
}
