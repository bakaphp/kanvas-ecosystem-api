<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\CustomerSuccess;

use Kanvas\Guild\Organizations\Actions\RecordOrganizationNoteAction;
use Kanvas\Intelligence\Agents\Approvals\CustomerUpdateApprovalHandler;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateDraft;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateRenderer;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;

/**
 * Puts a drafted update in front of a human: the copy lands in the account's notes thread as a locked
 * approval card, and nothing is mailed until someone approves it.
 *
 * The note is the record either way — it is what next month's draft reads to know what this account has
 * already been told. Requesting approval writes it; approving sends it and advances the watermark.
 */
class RequestCustomerUpdateApprovalAction
{
    public function __construct(
        private readonly CustomerUpdateDraft $draft,
        private readonly Users $requestedBy,
        private readonly string $recipient,
    ) {
    }

    public function execute(): ?Message
    {
        $note = new RecordOrganizationNoteAction($this->draft->organization)->execute(
            body: new CustomerUpdateRenderer()->toMarkdown($this->draft),
            tag: 'newsletter',
            actingUser: $this->requestedBy,
            fromIa: true,
            isPublic: false,
        );

        if ($note === null) {
            return null;
        }

        // The recipient rides on the message the way every other email draft carries it, so the card
        // and anything SendAgentEmailAction-shaped read it from the same place.
        $note->addMessage(['chat_jid' => $this->recipient]);

        new RequestMessageApprovalAction(
            message: $note,
            kind: MessageApproval::KIND_EMAIL_DRAFT,
            handler: CustomerUpdateApprovalHandler::class,
            context: [
                'organization_id' => $this->draft->organization->getId(),
                'subject' => $this->draft->subject,
                'recipient' => $this->recipient,
                'covered_through' => $this->draft->coveredThrough?->toIso8601String(),
                'release_tags' => $this->draft->releaseTags,
            ],
        )->execute();

        return $note->refresh();
    }
}
