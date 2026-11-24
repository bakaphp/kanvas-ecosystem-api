<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Concerns;

use Baka\Support\Str;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Users\Models\Users;

/**
 * The Slack half of a Scribe document's approval notices (DeliversApprovalNoticesInterface): a DM from
 * the document's notifier agent with the source PDF attached. Lives on the document rather than in the
 * agent tools so a bill submitted from GraphQL, the inbox or an import notifies exactly like one the
 * agent filed.
 */
trait NotifiesApproversOnSlack
{
    use ReadsApprovalSourceFields;

    /** "bill" / "invoice" — also the word the approver replies with ("approve bill 12"). */
    abstract protected function approvalNoun(): string;

    /** The app setting holding the notifier agent's id. */
    abstract protected function approvalNotifierSettingKey(): string;

    /** What the approver needs to decide: counterparty, amount, lines, memo. */
    abstract protected function approvalNoticeBody(): string;

    /** Null falls back to the app's AP notifier agent. */
    public function approvalNotifierAgentId(): ?string
    {
        return Str::trimToNull((string) ($this->app->get($this->approvalNotifierSettingKey()) ?? ''));
    }

    public function deliverApprovalNotice(ApprovalRequest $request, Users $recipient): bool
    {
        $text = $request->status === ApprovalStatusEnum::EXPIRED
            ? $this->expiredApprovalNoticeText($request)
            : $this->pendingApprovalNoticeText();

        return $this->sendApprovalDirectMessage((string) $recipient->email, $text);
    }

    /**
     * The legacy accounting.approval_queue path has no ApprovalRequest to hand the approvals domain, only
     * the approver emails it resolved.
     *
     * @param list<string> $approverEmails
     */
    public function notifyApproverEmailsOnSlack(array $approverEmails): void
    {
        $text = $this->pendingApprovalNoticeText();

        foreach ($approverEmails as $email) {
            $this->sendApprovalDirectMessage($email, $text);
        }
    }

    private function pendingApprovalNoticeText(): string
    {
        $noun = $this->approvalNoun();

        return $this->approvalNoticeBody() . "\n\nReply \"approve {$noun} {$this->getKey()}\" to approve it.";
    }

    private function expiredApprovalNoticeText(ApprovalRequest $request): string
    {
        $noun = $this->approvalNoun();

        return "The approval request for {$noun} {$this->getKey()} expired with no decision "
            . "(request #{$request->getId()}). It is back in draft for review and has to be submitted again.\n\n"
            . $this->approvalNoticeBody();
    }

    private function sendApprovalDirectMessage(string $email, string $text): bool
    {
        $attachment = $this->attachmentFields($this);

        return new NotifyApproverAction(
            app: $this->app,
            text: $text,
            approverEmail: $email,
            attachmentUrl: $attachment['source_attachment_url'],
            attachmentFilename: $attachment['source_attachment_filename'],
            agentId: $this->approvalNotifierAgentId(),
        )->execute();
    }
}
